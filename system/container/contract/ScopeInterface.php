<?php

declare(strict_types=1);

namespace System\Container\Contract;

interface ScopeInterface
{
    public function name(): string;

    public function get(string $id): mixed;

    public function has(string $id): bool;

    /**
     * @param callable():mixed $factory
     */
    public function remember(string $id, callable $factory): mixed;

    public function set(string $id, mixed $instance): void;

    public function dispose(): void;

    public function isDisposed(): bool;
}
