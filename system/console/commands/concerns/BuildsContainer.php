<?php

declare(strict_types=1);

namespace System\Console\Commands\Concerns;

use System\Container\Compilation\CompilationResult;
use System\Container\Compilation\ContainerCompiler;
use System\Container\Compilation\Diagnostic;
use System\Container\Compilation\Severity;
use System\Container\Core\ContainerBuilder;
use System\Runtime\RuntimeInstances;
use System\Runtime\Sapi;
use System\Runtime\ShutdownRegistry;

trait BuildsContainer
{
    private ?ContainerBuilder $cachedBuilder = null;

    /**
     * @param Sapi|null $sapi
     */
    protected function builder(?Sapi $sapi = null): ContainerBuilder
    {
        return $this->cachedBuilder ??= ContainerBuilder::fromProviders(
            appRoot: APP_ROOT,
            sapi: $sapi,

            instances: RuntimeInstances::build(APP_ROOT, new ShutdownRegistry(), validate: false),
        );
    }

    protected function compiler(?Sapi $sapi = null, bool $useContentHash = false): ContainerCompiler
    {
        return ContainerCompiler::fromBuilder(
            appRoot: APP_ROOT,
            builder: $this->builder($sapi),
            useContentHash: $useContentHash,
        );
    }

    /**
     * @return array{id: ?string, candidates: list<string>, alias: ?string}
     */
    protected function findService(CompilationResult $result, string $query): array
    {
        $query = ltrim($query, '\\');

        if (isset($result->definitions[$query])) {
            return ['id' => $query, 'candidates' => [], 'alias' => null];
        }

        $bindings = $this->builder()->bindings();
        if ($bindings->has($query)) {
            $target = $bindings->resolve($query);

            if (isset($result->definitions[$target])) {
                return ['id' => $target, 'candidates' => [], 'alias' => $query];
            }
        }

        $candidates = array_values(array_filter(
            array_keys($result->definitions),
            static fn(string $id): bool => stripos($id, $query) !== false
        ));

        if (count($candidates) === 1) {
            return ['id' => $candidates[0], 'candidates' => [], 'alias' => null];
        }

        $exact = array_values(array_filter(
            $candidates,
            static fn(string $id): bool => strcasecmp(substr($id, (int) strrpos('\\' . $id, '\\')), $query) === 0
        ));

        if (count($exact) === 1) {
            return ['id' => $exact[0], 'candidates' => [], 'alias' => null];
        }

        return ['id' => null, 'candidates' => $candidates, 'alias' => null];
    }

    /**
     * @param array{id: ?string, candidates: list<string>, alias: ?string} $found
     */
    protected function reportServiceLookup(string $query, array $found): void
    {
        if ($found['candidates'] === []) {
            $this->error("Servis bulunamadı: '{$query}'");
            $this->newLine();
            $this->note('Tüm servisler için: php frame container:list');

            return;
        }

        $this->warning(count($found['candidates']) . ' servis eşleşti — hangisi?');
        $this->newLine();
        $this->listing(array_slice($found['candidates'], 0, 25));

        if (count($found['candidates']) > 25) {
            $this->writeln($this->output->gray('  … ve ' . (count($found['candidates']) - 25) . ' tane daha'));
        }
    }

    /**
     * @param list<Diagnostic> $diagnostics
     * @param bool $full
     */
    protected function renderDiagnostics(array $diagnostics, bool $full = true): void
    {
        if ($diagnostics === []) {
            return;
        }

        foreach ([Severity::ERROR, Severity::WARNING, Severity::NOTICE] as $severity) {
            $group = array_values(array_filter(
                $diagnostics,
                static fn(Diagnostic $d): bool => $d->severity === $severity
            ));

            if ($group === []) {
                continue;
            }

            $this->newLine();
            $this->section($severity->label() . ' (' . count($group) . ')');

            foreach ($group as $diagnostic) {
                if ($full) {
                    $this->writeln($this->colorize($severity, $diagnostic->render()));
                    $this->newLine();
                } else {
                    $this->writeln('  ' . $this->colorize($severity, $diagnostic->summary()));
                }
            }
        }
    }

    private function colorize(Severity $severity, string $text): string
    {
        return match ($severity) {
            Severity::ERROR   => $this->output->red($text),
            Severity::WARNING => $this->output->yellow($text),
            Severity::NOTICE  => $this->output->gray($text),
        };
    }

    protected function diagnosticSummary(array $diagnostics): string
    {
        $counts = [Severity::ERROR->value => 0, Severity::WARNING->value => 0, Severity::NOTICE->value => 0];

        foreach ($diagnostics as $diagnostic) {
            $counts[$diagnostic->severity->value]++;
        }

        return $this->output->red($counts['error'] . ' hata') . ', '
            . $this->output->yellow($counts['warning'] . ' uyarı') . ', '
            . $this->output->gray($counts['notice'] . ' bilgi');
    }
}
