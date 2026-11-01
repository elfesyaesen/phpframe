<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Lifetime\Lifetime;

final class ScopeValidator
{
    /**
     * @return list<Diagnostic>
     */
    public function validate(DependencyGraph $graph): array
    {
        $diagnostics = [];

        foreach ($graph->ids() as $id) {
            $parent = $graph->definition($id);
            if ($parent === null) {
                continue;
            }

            foreach ($graph->edges($id) as $target) {
                $child = $graph->definition($target);
                if ($child === null) {
                    continue;
                }

                if ($parent->lifetime === Lifetime::SINGLETON && $child->lifetime === Lifetime::SCOPED) {
                    $diagnostics[] = $this->captureError($graph, [$id, $target]);
                    continue;
                }

                if ($parent->lifetime === Lifetime::SINGLETON && $child->lifetime === Lifetime::TRANSIENT) {
                    $diagnostics[] = Diagnostic::warning(
                        'TRANSIENT_PINNED_BY_SINGLETON',
                        'Singleton bir transient servisi pinliyor.',
                        $this->describeChain($graph, [$id, $target]),
                        'transient fiilen singleton olur',
                        "{$target} her çözümlemede yeni örnek üretmek üzere tanımlı, ama\n"
                        . "{$id} onu bir kez kurup ömrü boyunca tutuyor — yani pratikte\n"
                        . "singleton'dır.\n"
                        . "Genelde bu yanlış etiketlenmiş bir lifetime'dır (hata değil,\n"
                        . "çünkü bazen kasıtlıdır).\n"
                        . "Çözüm: {$target} gerçekten paylaşılıyorsa singleton olarak işaretle;\n"
                        . "her seferinde yeni örnek gerekiyorsa {$id} içine bir factory enjekte et.",
                        $id,
                        $parent->source,
                    );
                }
            }
        }

        foreach ($graph->ids() as $id) {
            if ($graph->lifetime($id) !== Lifetime::SINGLETON) {
                continue;
            }

            foreach ($this->transitiveScopedPaths($graph, $id) as $path) {
                if (count($path) > 2) {
                    $diagnostics[] = $this->captureError($graph, $path);
                }
            }
        }

        return $diagnostics;
    }

    /**
     * @return list<list<string>>
     */
    private function transitiveScopedPaths(DependencyGraph $graph, string $root): array
    {
        $paths = [];
        /** @var array<string, true> */
        $visited = [];

        /** @var list<array{0: string, 1: list<string>}> */
        $stack = [[$root, [$root]]];

        while ($stack !== []) {
            [$current, $path] = array_pop($stack);

            foreach ($graph->edges($current) as $target) {
                $lifetime = $graph->lifetime($target);
                if ($lifetime === null) {
                    continue;
                }

                $next = [...$path, $target];

                if ($lifetime === Lifetime::SCOPED) {
                    $paths[] = $next;
                    continue;
                }

                if ($lifetime !== Lifetime::TRANSIENT) {
                    continue;
                }

                $key = $target . '|' . count($next);
                if (isset($visited[$key]) || count($next) > 24) {
                    continue;
                }
                $visited[$key] = true;

                $stack[] = [$target, $next];
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $path
     */
    private function captureError(DependencyGraph $graph, array $path): Diagnostic
    {
        $singleton = $path[0];
        $scoped = $path[count($path) - 1];

        return Diagnostic::error(
            'SINGLETON_CAPTURES_SCOPED',
            'Bir singleton, scoped bir servise bağımlı.',
            $this->describeChain($graph, $path),
            'scope ihlali',
            "{$singleton} tüm PHP-FPM worker ömrü boyunca yaşar; 1. isteğin\n"
            . "{$scoped} örneğini yakalar ve o worker'ın gördüğü HER sonraki\n"
            . "isteğe onu servis eder (DI-plan §8).\n"
            . "Dev'de worker tek istek gördüğü için bu asla görünmez;\n"
            . "production'da kullanıcı verisi karışır.\n"
            . "Çözüm 1: {$singleton}'ı scoped yap.\n"
            . "Çözüm 2: doğrudan enjekte etmek yerine bir locator enjekte et —\n"
            . "         her erişimde aktif scope'tan çözen ince bir façade\n"
            . "         (bkz. System\\Monitor\\RecorderLocator).",
            $singleton,
            $graph->definition($singleton)?->source,
        );
    }

    /**
     * @param list<string> $chain
     * @return list<string>
     */
    private function describeChain(DependencyGraph $graph, array $chain): array
    {
        $width = 0;
        foreach ($chain as $id) {
            $width = max($width, strlen($id));
        }

        $lines = [];
        foreach ($chain as $id) {
            $definition = $graph->definition($id);
            $line = str_pad($id, $width);

            if ($definition !== null) {
                $line .= '  (' . $definition->lifetime->label() . ')';
                if ($definition->source !== null) {
                    $line .= '  ' . $definition->source;
                }
            }

            $lines[] = rtrim($line);
        }

        return $lines;
    }
}
