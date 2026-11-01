<?php

declare(strict_types=1);

namespace System\Cache;

abstract class AbstractCache implements CacheInterface
{
    private static ?object $miss = null;

    public function __construct(
        protected readonly string $prefix = '',
        protected readonly int $defaultTtl = 300,
    ) {
        self::$miss ??= new \stdClass();
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $sentinel = self::$miss;
        $value = $this->get($key, $sentinel);

        if ($value !== $sentinel) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    protected function normalizeTtl(?int $ttl): int
    {
        return $ttl ?? $this->defaultTtl;
    }

    protected function prefixed(string $key): string
    {
        return $this->prefix !== '' ? $this->prefix . ':' . $key : $key;
    }
}
