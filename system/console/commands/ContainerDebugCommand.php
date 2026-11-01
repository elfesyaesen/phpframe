<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\BuildsContainer;
use System\Console\ExitCode;
use System\Console\Input\InputArgument;
use System\Console\Input\InputOption;
use System\Container\Compilation\CompilationResult;
use System\Container\Context\ContextualBinding;
use System\Container\Definition\ArgumentKind;
use System\Container\Decorator\DecoratorFlattener;
use System\Container\Decorator\DecoratorRegistry;
use System\Container\Compilation\Diagnostic;
use System\Container\Lifetime\Lifetime;
use Throwable;

#[AsCommand(
    name: 'container:debug',
    description: 'Bir servisin bağımlılık ağacını, lifetime\'ını ve bulgularını gösterir',
    usage: 'php frame container:debug <servis> [--reverse] [--depth=N]',
    aliases: ['c:d']
)]
final class ContainerDebugCommand extends Command
{
    use BuildsContainer;

    protected function configure(): void
    {
        $this->addArgument(
            'service',
            InputArgument::REQUIRED,
            'Servis id\'si (tam veya kısmi; kısmi eşleşmede adaylar listelenir)'
        );

        $this->addOption(
            'reverse',
            'r',
            description: 'Bağımlılıklar yerine bu servisi İSTEYENLERİ gösterir'
        );

        $this->addOption(
            'depth',
            'd',
            InputOption::VALUE_REQUIRED,
            'Ağaç derinliği sınırı',
            '6'
        );
    }

    protected function handle(): ExitCode
    {
        $query = (string) $this->argument('service');

        try {
            $result = $this->compiler()->compile(generate: false);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return ExitCode::FAILURE;
        }

        $found = $this->findService($result, $query);
        $id = $found['id'];

        if ($id === null) {
            $this->reportServiceLookup($query, $found);

            return ExitCode::FAILURE;
        }

        $definition = $result->definitions[$id];
        $graph = $result->graph;
        $depth = max(1, (int) $this->option('depth'));

        $this->title($id);

        if ($found['alias'] !== null) {
            $this->writeln('  ' . $this->output->gray($found['alias'] . ' → ' . $id . ' (binding)'));
            $this->newLine();
        }

        if ((bool) $this->option('reverse')) {
            $this->section('Bu servisi isteyenler');
            $this->renderReverse($result, $id, $depth);
        } else {
            $this->section('Bağımlılık ağacı');
            $this->renderTree($result, $id, $depth);
        }

        $rows = [
            ['Lifetime', $definition->lifetime->label()],
            ['Kurulum', $definition->describeConstruction()],
            ['Bağımlılık', (string) count($definition->edges())],
            ['İsteyen', (string) count($graph->dependents($id))],
            ['Kaynak', $definition->source ?? '—'],
            ['Autowired', $definition->autowired ? 'evet (örtük)' : 'hayır (açık tanım)'],
            ['Kök', $result->roots[$id] ?? '—'],
        ];

        $requiresScope = $this->requiresScope($result, $id);
        if ($requiresScope !== null) {
            $rows[] = ['Scope gerekli', $requiresScope];
        }

        $this->newLine();
        $this->table(['özellik', 'değer'], $rows);

        $this->renderPhaseFive($id, $result);

        $related = array_values(array_filter(
            $result->diagnostics,
            fn(Diagnostic $d): bool => $d->service === $id || $this->chainMentions($d->chain, $id)
        ));

        if ($related !== []) {
            $this->renderDiagnostics($related);
        }

        return ExitCode::SUCCESS;
    }

    private function renderPhaseFive(string $id, CompilationResult $result): void
    {
        $builder = $this->builder();

        $consumed = [];

        foreach ($result->definitions[$id]->arguments ?? [] as $argument) {
            if ($argument->kind === ArgumentKind::TAGGED) {
                $consumed[(string) $argument->id] = $argument->ids ?? [];
            }
        }

        if ($consumed !== []) {
            $this->newLine();
            $this->section('Tüketilen etiketler (§20)');

            foreach ($consumed as $tag => $members) {
                $this->writeln('  #' . $tag . $this->output->gray(
                    '  → ' . count($members) . ' üye, bildirilen sırayla enjekte edilir'
                ));

                foreach ($members as $index => $member) {
                    $this->writeln('    ' . ($index + 1) . '. ' . $member);
                }
            }
        }

        $tags = $builder->tags()->tagsOf($id);

        if ($tags !== []) {
            $this->newLine();
            $this->section('Üye olunan etiketler (§20)');

            foreach ($tags as $tag) {
                $members = $builder->tags()->members($tag);
                $position = array_search($id, $members, true);

                $this->writeln(sprintf(
                    '  #%s  — %d üyeden %d. sırada',
                    $tag,
                    count($members),
                    $position === false ? 0 : $position + 1,
                ));

                foreach ($members as $index => $member) {
                    $marker = $member === $id ? $this->output->cyan(' ←') : '';
                    $this->writeln('    ' . ($index + 1) . '. ' . $member . $marker);
                }
            }
        }

        $publicId = DecoratorFlattener::publicIdOf($id);
        $chain = $builder->decorators()->chain($publicId);

        if ($chain !== []) {
            $this->newLine();
            $this->section('Dekoratör zinciri (§21)');
            $this->writeln($this->output->gray('  dıştan içe:'));

            foreach (array_reverse($chain) as $layer) {
                $marker = $layer['class'] === ($this->builder()->definitions()->get($id)?->concrete)
                    ? $this->output->cyan(' ←')
                    : '';

                $this->writeln('    ' . $layer['class'] . $marker
                    . ($layer['source'] !== null ? $this->output->gray('  ' . $layer['source']) : ''));
            }

            $innerDefinition = $this->builder()->definitions()->get(
                DecoratorRegistry::innerId($publicId, 0)
            );

            if ($innerDefinition !== null) {
                $this->writeln('    ' . $innerDefinition->concrete
                    . $this->output->gray('  (çekirdek)'));
            }
        }

        $contextual = array_values(array_filter(
            $builder->contextual()->all(),
            static fn(ContextualBinding $binding): bool => $binding->consumer === $id
        ));

        if ($contextual !== []) {
            $this->newLine();
            $this->section('Bağlamsal binding (§12)');

            foreach ($contextual as $binding) {
                $this->writeln('  ' . $binding->describe()
                    . ($binding->source !== null ? $this->output->gray('  ' . $binding->source) : ''));
            }
        }
    }

    private function renderTree(CompilationResult $result, string $id, int $maxDepth): void
    {
        $this->renderNode($result, $id, $maxDepth, 0, [], true, '');
    }

    /**
     * @param array<string, true> $onPath
     */
    private function renderNode(
        CompilationResult $result,
        string $id,
        int $maxDepth,
        int $depth,
        array $onPath,
        bool $isLast,
        string $prefix,
    ): void {
        $definition = $result->definitions[$id] ?? null;
        $label = $this->label($id, $definition?->lifetime);

        if ($depth === 0) {
            $this->writeln('  ' . $label);
        } else {
            $connector = $isLast ? ' └── ' : ' ├── ';
            $this->writeln('  ' . $prefix . $connector . $label);
        }

        if ($definition === null) {
            return;
        }

        if (isset($onPath[$id])) {
            $childPrefix = $prefix . ($depth === 0 ? '' : ($isLast ? '     ' : ' │   '));
            $this->writeln('  ' . $childPrefix . ' └── ' . $this->output->red('↺ döngü'));

            return;
        }

        if ($depth >= $maxDepth) {
            $edges = count($result->graph->edges($id));
            if ($edges > 0) {
                $childPrefix = $prefix . ($depth === 0 ? '' : ($isLast ? '     ' : ' │   '));
                $this->writeln('  ' . $childPrefix . ' └── ' . $this->output->gray("… {$edges} dal daha (--depth)"));
            }

            return;
        }

        $onPath[$id] = true;

        $edges = $result->graph->edges($id);
        $count = count($edges);
        $childPrefix = $prefix . ($depth === 0 ? '' : ($isLast ? '     ' : ' │   '));

        foreach ($edges as $index => $target) {
            $this->renderNode(
                $result,
                $target,
                $maxDepth,
                $depth + 1,
                $onPath,
                $index === $count - 1,
                $childPrefix,
            );
        }
    }

    private function renderReverse(CompilationResult $result, string $id, int $maxDepth): void
    {
        if ($result->graph->dependents($id) === []) {
            $this->writeln('  ' . $this->output->gray('(hiçbir servis bunu istemiyor — kök veya ölü tanım)'));

            return;
        }

        $this->renderDependent($result, $id, $maxDepth, 0, [], true, '');
    }

    /**
     * @param array<string, true> $onPath
     */
    private function renderDependent(
        CompilationResult $result,
        string $id,
        int $maxDepth,
        int $depth,
        array $onPath,
        bool $isLast,
        string $prefix,
    ): void {
        $label = $this->label($id, $result->definitions[$id]->lifetime ?? null);
        $childPrefix = $prefix . ($depth === 0 ? '' : ($isLast ? '     ' : ' │   '));

        $this->writeln('  ' . ($depth === 0 ? '' : $prefix . ($isLast ? ' └── ' : ' ├── ')) . $label);

        if (isset($onPath[$id])) {
            $this->writeln('  ' . $childPrefix . ' └── ' . $this->output->red('↺ döngü'));

            return;
        }

        $dependents = $result->graph->dependents($id);

        if ($depth >= $maxDepth) {
            if ($dependents !== []) {
                $this->writeln('  ' . $childPrefix . ' └── ' . $this->output->gray('… ' . count($dependents) . ' dal daha (--depth)'));
            }

            return;
        }

        $onPath[$id] = true;
        $count = count($dependents);

        foreach (array_values($dependents) as $index => $dependent) {
            $this->renderDependent($result, $dependent, $maxDepth, $depth + 1, $onPath, $index === $count - 1, $childPrefix);
        }
    }

    private function label(string $id, ?Lifetime $lifetime): string
    {
        if ($lifetime === null) {
            return $id . '  ' . $this->output->red('(tanımsız)');
        }

        $tag = match ($lifetime) {
            Lifetime::SCOPED    => $this->output->cyan('(' . $lifetime->value . ')'),
            Lifetime::SINGLETON => $this->output->yellow('(' . $lifetime->value . ')'),
            Lifetime::INSTANCE  => $this->output->gray('(' . $lifetime->value . ')'),
            Lifetime::TRANSIENT => $this->output->gray('(' . $lifetime->value . ')'),
        };

        return $id . '  ' . $tag;
    }

    private function requiresScope(CompilationResult $result, string $id): ?string
    {
        $definition = $result->definitions[$id] ?? null;

        if ($definition === null) {
            return null;
        }

        if ($definition->lifetime === Lifetime::SCOPED) {
            return 'evet — kendisi scoped';
        }

        $seen = [$id => true];
        $queue = $result->graph->edges($id);

        while ($queue !== []) {
            $target = array_shift($queue);

            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;

            $lifetime = $result->definitions[$target]->lifetime ?? null;

            if ($lifetime === Lifetime::SCOPED) {
                return 'evet — scoped ' . $target . ' bağımlılığı var';
            }

            if ($lifetime === Lifetime::TRANSIENT) {
                array_push($queue, ...$result->graph->edges($target));
            }
        }

        return null;
    }

    /**
     * @param list<string> $chain
     */
    private function chainMentions(array $chain, string $id): bool
    {
        $pattern = '/(?<![\w\\\\])' . preg_quote($id, '/') . '(?![\w\\\\])/';

        foreach ($chain as $line) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }
}
