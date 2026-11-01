<?php

declare(strict_types=1);

namespace System\Cache;

final class NullCache extends AbstractCache
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function flush(): bool
    {
        return true;
    }
}
