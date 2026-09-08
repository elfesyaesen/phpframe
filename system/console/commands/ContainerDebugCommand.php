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

/**
 * Tek bir servisi inceler (DI-plan §36).
 *
 * Çıktı DI-plan §36'daki ağaç biçimini izler, üzerine kaynak konumu,
 * "scope gerekiyor mu", tersine bağımlılıklar ve o node'a değen bulgular
 * eklenir.
 */
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

        $id = $this->resolveId($query, $result);

        if ($id === null) {
            return ExitCode::FAILURE;
        }

        $definition = $result->definitions[$id];
        $graph = $result->graph;
        $depth = max(1, (int) $this->option('depth'));

        $this->title($id);

        // ── Ağaç ───────────────────────────────────────────────────
        if ((bool) $this->option('reverse')) {
            $this->section('Bu servisi isteyenler');
            $this->renderReverse($result, $id, $depth);
        } else {
            $this->section('Bağımlılık ağacı');
            $this->renderTree($result, $id, $depth);
        }

        // ── Özet ───────────────────────────────────────────────────
        $liveHash = $this->loader()->liveHash();
        $compiled = $liveHash === $result->hash;

        $rows = [
            ['Lifetime', $definition->lifetime->label()],
            ['Kurulum', $definition->describeConstruction()],
            ['Bağımlılık', (string) count($definition->edges())],
            ['İsteyen', (string) count($graph->dependents($id))],
            ['Kaynak', $definition->source ?? '—'],
            ['Autowired', $definition->autowired ? 'evet (örtük)' : 'hayır (açık tanım)'],
            ['Kök', $result->roots[$id] ?? '—'],
            ['Derlenmiş', $compiled ? 'evet' : 'hayır (derleme bayat veya yok)'],
        ];

        // Scope gerekliliği: yalnızca scoped ebeveynlerden erişilen bir
        // transient, istek dışında kullanılamaz — bunu bilmek CLI'dan aynı
        // servisi çözmeye çalışan geliştiriciye zaman kazandırır.
        $requiresScope = $this->requiresScope($result, $id);
        if ($requiresScope !== null) {
            $rows[] = ['Scope gerekli', $requiresScope];
        }

        $this->newLine();
        $this->table(['özellik', 'değer'], $rows);

        $this->renderPhaseFive($id, $result);

        // ── Bu node'a değen bulgular ───────────────────────────────
        $related = array_values(array_filter(
            $result->diagnostics,
            fn(Diagnostic $d): bool => $d->service === $id || $this->chainMentions($d->chain, $id)
        ));

        if ($related !== []) {
            $this->renderDiagnostics($related);
        }

        return ExitCode::SUCCESS;
    }

    /**
     * Faz 5 bilgileri: etiketler, dekoratör zinciri, bağlamsal binding'ler.
     *
     * Bunlar "servis nasıl kuruluyor" sorusunun cevabını değiştirir ama
     * bağımlılık ağacında GÖRÜNMEZ — dekoratör zinciri dahili id'ler
     * üzerinden ilerler, bağlamsal binding ise tip yerine tüketiciye bakar.
     * Ayrı gösterilmeleri bu yüzden.
     */
    private function renderPhaseFive(string $id, CompilationResult $result): void
    {
        $builder = $this->builder();

        // ── TÜKETİLEN etiketler ──
        //
        // Bir servis etiketin ÜYESİ olabilir (aşağıda) veya bir etiketi
        // TÜKETİYOR olabilir (`#[Tagged]` parametresi). İkisi farklı
        // sorular; ikincisi "bu servise ne enjekte ediliyor" demek.
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

        // ── ÜYE olunan etiketler ──
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

        // ── Dekoratör zinciri ──
        $publicId = DecoratorFlattener::publicIdOf($id);
        $chain = $builder->decorators()->chain($publicId);

        if ($chain !== []) {
            $this->newLine();
            $this->section('Dekoratör zinciri (§21)');
            $this->writeln($this->output->gray('  dıştan içe:'));

            // Registry ekleme sırasında; en dış SON eklenen.
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

        // ── Bağlamsal binding'ler ──
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

    /**
     * Kısmi sorguyu tam id'ye çevirir; belirsizse adayları listeler.
     */
    private function resolveId(string $query, CompilationResult $result): ?string
    {
        if (isset($result->definitions[$query])) {
            return $query;
        }

        $matches = array_values(array_filter(
            array_keys($result->definitions),
            static fn(string $id): bool => stripos($id, $query) !== false
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }

        if ($matches === []) {
            $this->error("Servis bulunamadı: '{$query}'");

            if (isset($result->skipped[$query])) {
                $this->newLine();
                $this->warning(
                    'Bu sınıf derleme kapsamına GİRMEDİ:' . "\n"
                    . '  ' . $result->skipped[$query]
                );
            } else {
                $this->newLine();
                $this->note('Tüm servisler için: php frame container:list');
            }

            return null;
        }

        $this->warning(count($matches) . " servis eşleşti — hangisi?");
        $this->newLine();
        $this->listing(array_slice($matches, 0, 25));

        if (count($matches) > 25) {
            $this->writeln($this->output->gray('  … ve ' . (count($matches) - 25) . ' tane daha'));
        }

        return null;
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

        // GRAFİN kenarları kullanılır, tanımın ham kenarları DEĞİL: graf
        // hedefleri alias zinciri üzerinden çözer (ScrubberInterface →
        // KeyScrubber). Ham kenarlar arayüz adı taşıdığı için ağaçta
        // "derlenmemiş" görünürlerdi — yalancı bir uyarı.
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
        $dependents = $result->graph->dependents($id);

        if ($dependents === []) {
            $this->writeln('  ' . $this->output->gray('(hiçbir servis bunu istemiyor — kök veya ölü tanım)'));

            return;
        }

        $this->writeln('  ' . $this->label($id, $result->definitions[$id]->lifetime ?? null));

        $count = count($dependents);
        foreach ($dependents as $index => $dependent) {
            $connector = $index === $count - 1 ? ' └── ' : ' ├── ';
            $this->writeln('  ' . $connector . $this->label(
                $dependent,
                $result->definitions[$dependent]->lifetime ?? null
            ));
        }
    }

    private function label(string $id, ?Lifetime $lifetime): string
    {
        if ($lifetime === null) {
            return $id . '  ' . $this->output->red('(derlenmemiş)');
        }

        $tag = match ($lifetime) {
            Lifetime::SCOPED    => $this->output->cyan('(' . $lifetime->value . ')'),
            Lifetime::SINGLETON => $this->output->yellow('(' . $lifetime->value . ')'),
            Lifetime::INSTANCE  => $this->output->gray('(' . $lifetime->value . ')'),
            Lifetime::TRANSIENT => $this->output->gray('(' . $lifetime->value . ')'),
        };

        return $id . '  ' . $tag;
    }

    /**
     * Bu servis yalnızca scope içinde çözülebilir mi?
     */
    private function requiresScope(CompilationResult $result, string $id): ?string
    {
        $definition = $result->definitions[$id] ?? null;

        if ($definition === null) {
            return null;
        }

        if ($definition->lifetime === Lifetime::SCOPED) {
            return 'evet — kendisi scoped';
        }

        foreach ($definition->edges() as $target) {
            if (($result->definitions[$target]->lifetime ?? null) === Lifetime::SCOPED) {
                return 'evet — scoped ' . $target . ' bağımlılığı var';
            }
        }

        return null;
    }

    /**
     * Zincir satırları biçimlenmiş olduğu için ("Foo  (singleton)  file:12")
     * tam eşitlik yetmez; alt dize araması gerekir.
     *
     * @param list<string> $chain
     */
    private function chainMentions(array $chain, string $id): bool
    {
        foreach ($chain as $line) {
            if (str_contains($line, $id)) {
                return true;
            }
        }

        return false;
    }
}
