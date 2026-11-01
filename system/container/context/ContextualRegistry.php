<?php

declare(strict_types=1);

namespace System\Container\Context;

final class ContextualRegistry
{
    /**
     * @var array<string, array<string, ContextualBinding>>
     */
    private array $bindings = [];

    public function add(ContextualBinding $binding): void
    {
        $this->bindings[$binding->consumer][$binding->needs] = $binding;
    }

    /**
     * @param class-string $consumer
     */
    public function find(string $consumer, ?string $type, string $parameterName): ?ContextualBinding
    {
        $forConsumer = $this->bindings[$consumer] ?? null;

        if ($forConsumer === null) {
            return null;
        }

        $byName = $forConsumer['$' . $parameterName] ?? null;
        if ($byName !== null) {
            return $byName;
        }

        return $type === null ? null : ($forConsumer[$type] ?? null);
    }

    public function hasConsumer(string $consumer): bool
    {
        return isset($this->bindings[$consumer]);
    }

    public function isEmpty(): bool
    {
        return $this->bindings === [];
    }

    /**
     * @return list<ContextualBinding>
     */
    public function all(): array
    {
        $consumers = $this->bindings;
        ksort($consumers);

        $out = [];
        foreach ($consumers as $needs) {
            ksort($needs);
            foreach ($needs as $binding) {
                $out[] = $binding;
            }
        }

        return $out;
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->bindings));
    }
}
