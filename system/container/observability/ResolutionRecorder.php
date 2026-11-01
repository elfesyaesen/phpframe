<?php

declare(strict_types=1);

namespace System\Container\Observability;

use System\Container\Lifetime\Lifetime;

final class ResolutionRecorder
{
    /** @var array<string, int> */
    private array $resolutions = [];

    /** @var array<string, int> */
    private array $instantiations = [];

    /** @var array<string, float> */
    private array $durations = [];

    /** @var array<string, string> */
    private array $lifetimes = [];

    private int $scopesCreated = 0;
    private int $scopesDisposed = 0;

    public function recordResolution(string $id): void
    {
        $this->resolutions[$id] = ($this->resolutions[$id] ?? 0) + 1;
    }

    public function recordInstantiation(string $id, Lifetime $lifetime, float $durationMs): void
    {
        $this->instantiations[$id] = ($this->instantiations[$id] ?? 0) + 1;
        $this->durations[$id] = ($this->durations[$id] ?? 0.0) + $durationMs;
        $this->lifetimes[$id] = $lifetime->value;
    }

    public function recordScopeCreated(): void
    {
        $this->scopesCreated++;
    }

    public function recordScopeDisposed(): void
    {
        $this->scopesDisposed++;
    }

    /**
     * @return list<array{ id: string, lifetime: string, resolutions: int, instantiations: int, ms: float, note: string }>
     */
    public function report(): array
    {
        $rows = [];

        foreach ($this->instantiations as $id => $count) {
            $resolutions = $this->resolutions[$id] ?? $count;
            $lifetime = $this->lifetimes[$id] ?? '?';

            $rows[] = [
                'id' => $id,
                'lifetime' => $lifetime,
                'resolutions' => $resolutions,
                'instantiations' => $count,
                'ms' => round($this->durations[$id] ?? 0.0, 3),
                'note' => $this->noteFor($lifetime, $resolutions, $count),
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => $b['instantiations'] <=> $a['instantiations']
        );

        return $rows;
    }

    private function noteFor(string $lifetime, int $resolutions, int $instantiations): string
    {
        if ($instantiations > 1 && ($lifetime === 'singleton' || $lifetime === 'instance')) {
            return 'DİKKAT: paylaşılan servis ' . $instantiations . ' kez kuruldu';
        }

        if ($lifetime === 'transient' && $instantiations === 1 && $resolutions === 1) {
            return 'transient ama tek kez istendi — singleton olabilir';
        }

        if ($lifetime === 'transient' && $instantiations > 5) {
            return $instantiations . ' kez kuruldu — kurulum maliyetini ölç';
        }

        return '';
    }

    /**
     * @return array<string, int|float>
     */
    public function totals(): array
    {
        return [
            'resolutions' => array_sum($this->resolutions),
            'instantiations' => array_sum($this->instantiations),
            'distinct_services' => count($this->instantiations),
            'total_ms' => round(array_sum($this->durations), 3),
            'scopes_created' => $this->scopesCreated,
            'scopes_disposed' => $this->scopesDisposed,
        ];
    }

    public function hasLeakedScopes(): bool
    {
        return $this->scopesCreated > $this->scopesDisposed;
    }

    public function reset(): void
    {
        $this->resolutions = [];
        $this->instantiations = [];
        $this->durations = [];
        $this->lifetimes = [];
        $this->scopesCreated = 0;
        $this->scopesDisposed = 0;
    }
}
