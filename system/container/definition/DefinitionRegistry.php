<?php

declare(strict_types=1);

namespace System\Container\Definition;

use System\Container\Lifetime\Lifetime;

final class DefinitionRegistry
{
    /** @var array<string, Definition> */
    private array $definitions = [];

    public function set(Definition $definition): void
    {
        $this->definitions[$definition->id] = $definition;
    }

    public function get(string $id): ?Definition
    {
        return $this->definitions[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function remove(string $id): void
    {
        unset($this->definitions[$id]);
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * @return array<string, Definition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, Definition>
     */
    public function sorted(): array
    {
        $sorted = $this->definitions;
        ksort($sorted);

        return $sorted;
    }

    /**
     * @return array<string, Definition>
     */
    public function byLifetime(Lifetime $lifetime): array
    {
        return array_filter(
            $this->sorted(),
            static fn(Definition $d): bool => $d->lifetime === $lifetime
        );
    }

    /**
     * @return list<string>
     */
    public function externalIds(): array
    {
        $ids = [];
        foreach ($this->sorted() as $id => $definition) {
            if ($definition->isExternal()) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, Definition>
     */
    public function closureFactories(): array
    {
        return array_filter(
            $this->sorted(),
            static fn(Definition $d): bool => $d->factory !== null && !$d->factory->isCompilable()
        );
    }

    /**
     * @return array<string, Definition>
     */
    public function undeclaredFactories(): array
    {
        return array_filter(
            $this->sorted(),
            static fn(Definition $d): bool => $d->factory !== null && $d->dependsOn === []
        );
    }
}
