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
use System\Container\Lifetime\Lifetime;
use Throwable;

/**
 * Bağımlılık grafiğini dışa aktarır (DI-plan §36).
 *
 * DOT ve Mermaid biçimleri kasıtlı: grafik bir PR yorumuna yapıştırılabilir
 * veya dokümana render edilebilir hâle gelir. Bir mimari kararı tartışırken
 * "şu üç servis birbirine bağlı" demek yerine grafiği göstermek çok daha
 * hızlı sonuçlanır.
 */
#[AsCommand(
    name: 'container:graph',
    description: 'Bağımlılık grafiğini ağaç/DOT/Mermaid olarak dışa aktarır',
    usage: 'php frame container:graph [servis] [--format=tree|dot|mermaid] [--depth=N] [--reverse]',
    aliases: ['c:g']
)]
final class ContainerGraphCommand extends Command
{
    use BuildsContainer;

    protected function configure(): void
    {
        $this->addArgument(
            'service',
            InputArgument::OPTIONAL,
            'Kök servis (verilmezse tüm grafik)'
        );

        $this->addOption(
            'format',
            'F',
            InputOption::VALUE_REQUIRED,
            'Çıktı biçimi: tree, dot, mermaid',
            'tree'
        );

        $this->addOption(
            'depth',
            'd',
            InputOption::VALUE_REQUIRED,
            'Derinlik sınırı',
            '4'
        );

        $this->addOption(
            'reverse',
            'r',
            description: 'Kenarları ters çevirir (kim kimi istiyor)'
        );
    }

    protected function handle(): ExitCode
    {
        $format = strtolower((string) $this->option('format'));

        if (!in_array($format, ['tree', 'dot', 'mermaid'], true)) {
            $this->error("Bilinmeyen biçim: '{$format}' — geçerli: tree, dot, mermaid");

            return ExitCode::FAILURE;
        }

        try {
            $result = $this->compiler()->compile(generate: false);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return ExitCode::FAILURE;
        }

        $root = $this->argument('service');
        $depth = max(1, (int) $this->option('depth'));

        $edges = $root === null
            ? $this->allEdges($result)
            : $this->subgraphEdges($result, (string) $root, $depth);

        if ($root !== null && $edges === null) {
            $this->error("Servis bulunamadı: '{$root}'");
            $this->note('Tüm servisler için: php frame container:list');

            return ExitCode::FAILURE;
        }

        /** @var list<array{0: string, 1: string}> $edges */
        if ((bool) $this->option('reverse')) {
            $edges = array_map(
                static fn(array $edge): array => [$edge[1], $edge[0]],
                $edges
            );
        }

        $nodes = [];
        foreach ($edges as [$from, $to]) {
            $nodes[$from] = true;
            $nodes[$to] = true;
        }
        if ($root !== null) {
            $nodes[(string) $root] = true;
        }

        match ($format) {
            'dot' => $this->renderDot($result, $edges, array_keys($nodes)),
            'mermaid' => $this->renderMermaid($result, $edges, array_keys($nodes)),
            default => $this->renderTree($result, $edges, array_keys($nodes)),
        };

        return ExitCode::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function allEdges(CompilationResult $result): array
    {
        $edges = [];

        foreach ($result->definitions as $id => $definition) {
            foreach ($definition->edges() as $target) {
                $edges[] = [$id, $target];
            }
        }

        return $edges;
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private function subgraphEdges(CompilationResult $result, string $root, int $maxDepth): ?array
    {
        if (!isset($result->definitions[$root])) {
            $matches = array_values(array_filter(
                array_keys($result->definitions),
                static fn(string $id): bool => stripos($id, $root) !== false
            ));

            if (count($matches) !== 1) {
                return null;
            }

            $root = $matches[0];
        }

        $edges = [];
        $seen = [];
        /** @var list<array{0: string, 1: int}> */
        $queue = [[$root, 0]];

        while ($queue !== []) {
            [$id, $depth] = array_shift($queue);

            if (isset($seen[$id]) || $depth >= $maxDepth) {
                continue;
            }
            $seen[$id] = true;

            $definition = $result->definitions[$id] ?? null;
            if ($definition === null) {
                continue;
            }

            foreach ($definition->edges() as $target) {
                $edges[] = [$id, $target];
                $queue[] = [$target, $depth + 1];
            }
        }

        return $edges;
    }

    /**
     * @param list<array{0: string, 1: string}> $edges
     * @param list<string>                      $nodes
     */
    private function renderTree(CompilationResult $result, array $edges, array $nodes): void
    {
        $this->title('Bağımlılık grafiği');
        $this->writeln($this->output->gray(
            '  ' . count($nodes) . ' node, ' . count($edges) . ' kenar'
        ));
        $this->newLine();

        $byParent = [];
        foreach ($edges as [$from, $to]) {
            $byParent[$from][] = $to;
        }
        ksort($byParent);

        foreach ($byParent as $from => $targets) {
            $this->writeln('  ' . $this->label($result, $from));

            $count = count($targets);
            foreach ($targets as $index => $to) {
                $connector = $index === $count - 1 ? '   └── ' : '   ├── ';
                $this->writeln($connector . $this->label($result, $to));
            }

            $this->newLine();
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $edges
     * @param list<string>                      $nodes
     */
    private function renderDot(CompilationResult $result, array $edges, array $nodes): void
    {
        $this->writeln('digraph container {');
        $this->writeln('  rankdir=LR;');
        $this->writeln('  node [shape=box, fontname="monospace", fontsize=10];');
        $this->writeln('');

        sort($nodes);
        foreach ($nodes as $node) {
            $lifetime = $result->definitions[$node]->lifetime ?? null;

            $this->writeln(sprintf(
                '  %s [label=%s, style=filled, fillcolor="%s"];',
                $this->quote($node),
                $this->quote($this->shortName($node) . '\n' . ($lifetime?->value ?? 'derlenmemiş')),
                $this->color($lifetime),
            ));
        }

        $this->writeln('');
        foreach ($edges as [$from, $to]) {
            $this->writeln('  ' . $this->quote($from) . ' -> ' . $this->quote($to) . ';');
        }

        $this->writeln('}');
    }

    /**
     * @param list<array{0: string, 1: string}> $edges
     * @param list<string>                      $nodes
     */
    private function renderMermaid(CompilationResult $result, array $edges, array $nodes): void
    {
        $this->writeln('```mermaid');
        $this->writeln('graph LR');

        $idMap = [];
        sort($nodes);
        foreach ($nodes as $index => $node) {
            $short = 'n' . $index;
            $idMap[$node] = $short;

            $lifetime = $result->definitions[$node]->lifetime ?? null;
            $this->writeln(sprintf(
                '  %s["%s<br/>%s"]',
                $short,
                $this->shortName($node),
                $lifetime?->value ?? 'derlenmemiş',
            ));
        }

        foreach ($edges as [$from, $to]) {
            if (!isset($idMap[$from], $idMap[$to])) {
                continue;
            }

            $this->writeln('  ' . $idMap[$from] . ' --> ' . $idMap[$to]);
        }

        // Scoped node'lar renklendirilir: scope ihlallerini grafikte gözle
        // görmek, tabloda satır saymaktan hızlı.
        foreach ($nodes as $node) {
            if (($result->definitions[$node]->lifetime ?? null) === Lifetime::SCOPED) {
                $this->writeln('  style ' . $idMap[$node] . ' fill:#cfe8ff,stroke:#1c74d4');
            }
        }

        $this->writeln('```');
    }

    private function label(CompilationResult $result, string $id): string
    {
        $lifetime = $result->definitions[$id]->lifetime ?? null;

        if ($lifetime === null) {
            return $id . '  ' . $this->output->red('(derlenmemiş)');
        }

        $tag = match ($lifetime) {
            Lifetime::SCOPED    => $this->output->cyan('(scoped)'),
            Lifetime::SINGLETON => $this->output->yellow('(singleton)'),
            default             => $this->output->gray('(' . $lifetime->value . ')'),
        };

        return $id . '  ' . $tag;
    }

    private function color(?Lifetime $lifetime): string
    {
        return match ($lifetime) {
            Lifetime::SCOPED    => '#cfe8ff',
            Lifetime::SINGLETON => '#fff3cd',
            Lifetime::INSTANCE  => '#e9ecef',
            Lifetime::TRANSIENT => '#ffffff',
            null                => '#f8d7da',
        };
    }

    private function shortName(string $id): string
    {
        $position = strrpos($id, '\\');

        return $position === false ? $id : substr($id, $position + 1);
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
