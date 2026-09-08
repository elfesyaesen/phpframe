<?php

declare(strict_types=1);

namespace System\Cache;

/**
 * Ortak cache davranışı: prefix yönetimi ve remember() implementasyonu.
 * Somut sürücüler yalnızca rawGet/rawSet/rawHas/rawDelete/flush yazar.
 */
abstract class AbstractCache implements CacheInterface
{
    /** remember() içinde "miss" ayrımı için benzersiz sentinel. */
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

    /**
     * Verilen TTL'i normalize eder: null -> varsayılan TTL.
     */
    protected function normalizeTtl(?int $ttl): int
    {
        return $ttl ?? $this->defaultTtl;
    }

    /**
     * Anahtara prefix uygular (Redis namespace ayrımı için).
     */
    protected function prefixed(string $key): string
    {
        return $this->prefix !== '' ? $this->prefix . ':' . $key : $key;
    }
}
