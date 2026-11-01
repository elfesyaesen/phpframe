<?php

declare(strict_types=1);

namespace System\Container\Binding;

use System\Container\Exception\BindingException;

final class BindingRegistry
{
    /** @var array<string, Binding> */
    private array $bindings = [];

    public function set(Binding $binding): void
    {
        $this->bindings[$binding->id] = $binding;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    public function get(string $id): ?Binding
    {
        return $this->bindings[$id] ?? null;
    }

    public function remove(string $id): void
    {
        unset($this->bindings[$id]);
    }

    public function resolve(string $id): string
    {
        if (!isset($this->bindings[$id])) {
            return $id;
        }

        $seen = [$id => true];
        $chain = [$id];
        $current = $id;

        while (isset($this->bindings[$current])) {
            $next = $this->bindings[$current]->target;

            if ($next === $current) {
                return $current;
            }

            if (isset($seen[$next])) {
                $chain[] = $next;
                throw BindingException::aliasCycle($chain);
            }

            $seen[$next] = true;
            $chain[] = $next;
            $current = $next;
        }

        return $current;
    }

    /**
     * @return list<string>
     */
    public function chain(string $id): array
    {
        $chain = [$id];
        $seen = [$id => true];
        $current = $id;

        while (isset($this->bindings[$current])) {
            $next = $this->bindings[$current]->target;
            if ($next === $current || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $chain[] = $next;
            $current = $next;
        }

        return $chain;
    }

    /**
     * @return array<string, Binding>
     */
    public function all(): array
    {
        return $this->bindings;
    }

    /**
     * @return array<string, Binding>
     */
    public function sorted(): array
    {
        $sorted = $this->bindings;
        ksort($sorted);

        return $sorted;
    }

    public function count(): int
    {
        return count($this->bindings);
    }
}
