<?php

declare(strict_types=1);

namespace System\Config;

/**
 * Cache katmanı ayarları — `CACHE_*` sabitlerinin yerine.
 */
final readonly class CacheConfig
{
    public function __construct(
        public string $driver,
        public string $prefix,
        public int $defaultTtl,
        public RedisConfig $redis,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            driver: strtolower($env->string('CACHE_DRIVER', 'redis')),
            prefix: $env->string('CACHE_PREFIX', 'phpframe'),
            defaultTtl: $env->int('CACHE_DEFAULT_TTL', 300),
            redis: RedisConfig::fromEnv($env),
        );
    }

    public function usesRedis(): bool
    {
        return $this->driver === 'redis';
    }
}
