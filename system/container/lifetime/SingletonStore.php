<?php

declare(strict_types=1);

namespace System\Container\Lifetime;

/**
 * Container ömrü boyunca yaşayan örnekler.
 *
 * PHP-FPM'de "container ömrü" = worker ömrü, yani birçok ardışık istek.
 * Bu yüzden buraya per-request state koymak cross-request sızıntısıdır;
 * ScopeValidator (DI-plan §19) bu hatayı compile-time'da yakalar.
 */
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
     * Örnek varsa döndürür, yoksa üretip saklar.
     *
     * `array_key_exists` kullanılır, `??=` değil: bir factory meşru olarak
     * null döndürebilir ve `??=` her çağrıda yeniden üretirdi.
     *
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
