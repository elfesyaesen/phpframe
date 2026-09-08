<?php

declare(strict_types=1);

namespace System\Cache;

/**
 * Süreç-içi (in-memory) cache. Tek request ömrü boyunca yaşar.
 *
 * Kullanım: test ortamı ve Redis'in uygun olmadığı senaryolarda istek-seviyesi
 * memoization. Kalıcı değildir (request bitince kaybolur).
 */
final class ArrayCache extends AbstractCache
{
    /** @var array<string, array{value:mixed, expires:int}> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $k = $this->prefixed($key);

        if (!isset($this->store[$k])) {
            return $default;
        }

        $entry = $this->store[$k];
        if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
            unset($this->store[$k]);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl = $this->normalizeTtl($ttl);

        $this->store[$this->prefixed($key)] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];

        return true;
    }

    public function has(string $key): bool
    {
        $k = $this->prefixed($key);

        if (!isset($this->store[$k])) {
            return false;
        }

        $entry = $this->store[$k];
        if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
            unset($this->store[$k]);
            return false;
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$this->prefixed($key)]);
        return true;
    }

    public function flush(): bool
    {
        $this->store = [];
        return true;
    }
}
