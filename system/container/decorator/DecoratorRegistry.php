<?php

declare(strict_types=1);

namespace System\Container\Decorator;

final class DecoratorRegistry
{
    /**
     * @var array<string, list<array{class: string, source: ?string}>>
     */
    private array $decorators = [];

    public function add(string $id, string $decoratorClass, ?string $source = null): void
    {
        $this->decorators[$id][] = ['class' => $decoratorClass, 'source' => $source];
    }

    public function has(string $id): bool
    {
        return isset($this->decorators[$id]);
    }

    /**
     * @return list<array{class: string, source: ?string}>
     */
    public function chain(string $id): array
    {
        return $this->decorators[$id] ?? [];
    }

    public static function innerId(string $id, int $level): string
    {
        return $id . '@inner.' . $level;
    }

    /**
     * @return array<string, list<array{class: string, source: ?string}>>
     */
    public function all(): array
    {
        $decorators = $this->decorators;
        ksort($decorators);

        return $decorators;
    }

    public function isEmpty(): bool
    {
        return $this->decorators === [];
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->decorators));
    }
}
