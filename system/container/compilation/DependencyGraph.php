<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Binding\BindingRegistry;
use System\Container\Definition\Definition;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Lifetime\Lifetime;

final class DependencyGraph
{
    /** @var array<string, Definition> */
    private array $nodes = [];

    /** @var array<string, list<string>> */
    private array $edges = [];

    /** @var array<string, list<string>> */
    private array $reverse = [];

    /**
     * @param array<string, Definition> $definitions
     * @param BindingRegistry|null $bindings
     */
    public static function fromDefinitions(array $definitions, ?BindingRegistry $bindings = null): self
    {
        $graph = new self();

        ksort($definitions);

        foreach ($definitions as $id => $definition) {
            $graph->nodes[$id] = $definition;
            $graph->edges[$id] = [];
            $graph->reverse[$id] ??= [];
        }

        foreach ($graph->nodes as $id => $definition) {
            foreach ($definition->edges() as $rawTarget) {
                $target = $bindings?->resolve($rawTarget) ?? $rawTarget;

                $graph->edges[$id][] = $target;
                $graph->reverse[$target][] = $id;
            }

            $graph->edges[$id] = array_values(array_unique($graph->edges[$id]));
        }

        foreach ($graph->reverse as $id => $dependents) {
            $graph->reverse[$id] = array_values(array_unique($dependents));
            sort($graph->reverse[$id]);
        }

        return $graph;
    }

    public static function fromRegistry(DefinitionRegistry $registry, ?BindingRegistry $bindings = null): self
    {
        return self::fromDefinitions($registry->sorted(), $bindings);
    }

    /** @return array<string, Definition> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->nodes);
    }

    public function has(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    public function definition(string $id): ?Definition
    {
        return $this->nodes[$id] ?? null;
    }

    public function lifetime(string $id): ?Lifetime
    {
        return $this->nodes[$id]->lifetime ?? null;
    }

    /**
     * @return list<string>
     */
    public function edges(string $id): array
    {
        return $this->edges[$id] ?? [];
    }

    /**
     * @return list<string>
     */
    public function knownEdges(string $id): array
    {
        return array_values(array_filter(
            $this->edges($id),
            fn(string $target): bool => isset($this->nodes[$target])
        ));
    }

    /**
     * @return list<string>
     */
    public function dependents(string $id): array
    {
        return $this->reverse[$id] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function missing(): array
    {
        $missing = [];

        foreach ($this->edges as $id => $targets) {
            foreach ($targets as $target) {
                if (!isset($this->nodes[$target])) {
                    $missing[$target][] = $id;
                }
            }
        }

        ksort($missing);

        return $missing;
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    public function edgeCount(): int
    {
        return array_sum(array_map('count', $this->edges));
    }

    /**
     * @param callable(string $id, int $depth, list<string> $path): void $visit
     */
    public function walk(string $id, callable $visit, int $maxDepth = 32): void
    {
        $this->walkInternal($id, $visit, $maxDepth, 0, [], []);
    }

    /**
     * @param array<string, true> $onPath
     * @param list<string> $path
     */
    private function walkInternal(
        string $id,
        callable $visit,
        int $maxDepth,
        int $depth,
        array $onPath,
        array $path,
    ): void {
        $path[] = $id;
        $visit($id, $depth, $path);

        if ($depth >= $maxDepth || isset($onPath[$id])) {
            return;
        }

        $onPath[$id] = true;

        foreach ($this->edges($id) as $target) {
            $this->walkInternal($target, $visit, $maxDepth, $depth + 1, $onPath, $path);
        }
    }
}
