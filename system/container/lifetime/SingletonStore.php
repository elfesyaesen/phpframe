<?php

declare(strict_types=1);

namespace System\Container\Lifetime;

final class SingletonStore
{
    /** @var array<string, mixed> */
    private array $instances = [];

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }

    public function get(string $id): mixed
    {
        return $this->instances[$id] ?? null;
    }

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    /**
     * @param callable():mixed $factory
     */
    public function remember(string $id, callable $factory): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        return $this->instances[$id] = $factory();
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->instances);
    }

    public function count(): int
    {
        return count($this->instances);
    }
}
