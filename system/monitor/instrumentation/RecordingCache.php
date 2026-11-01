<?php

declare(strict_types=1);

namespace System\Monitor\Instrumentation;

use System\Cache\CacheInterface;
use System\Monitor\Contracts\RecorderInterface;

final class RecordingCache implements CacheInterface
{
    public function __construct(
        private readonly CacheInterface $inner,
        private readonly RecorderInterface $recorder,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->inner->get($key, $default);

        $this->recorder->recordCache($key, $value !== $default);

        return $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        $exists = $this->inner->has($key);
        $this->recorder->recordCache($key, $exists);

        return $exists;
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $executed = false;

        $value = $this->inner->remember(
            $key,
            $ttl,
            static function () use ($callback, &$executed): mixed {
                $executed = true;

                return $callback();
            }
        );

        $this->recorder->recordCache($key, !$executed);

        return $value;
    }

    public function flush(): bool
    {
        return $this->inner->flush();
    }
}
