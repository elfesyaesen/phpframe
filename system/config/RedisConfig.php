<?php

declare(strict_types=1);

namespace System\Config;

use SensitiveParameter;

/**
 * Redis bağlantı ayarları — `REDIS_*` sabitlerinin yerine.
 *
 * DI-plan §10'un örneği tam olarak budur: `RedisClient(string $host, int $port)`
 * yerine `RedisClient(RedisConfig $config)`. Primitive injection yerine typed
 * config, hem autowire edilebilir hem test edilebilir.
 */
final readonly class RedisConfig
{
    public function __construct(
        public string $host,
        public int $port,
        #[SensitiveParameter] public string $password,
        public int $database,
        public bool $persistent,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            host: $env->string('REDIS_HOST', '127.0.0.1'),
            port: $env->int('REDIS_PORT', 6379),
            password: $env->string('REDIS_PASSWORD'),
            database: $env->int('REDIS_DB', 0),
            // pconnect: FPM worker ömrü boyunca yeniden kullanılır.
            persistent: $env->bool('REDIS_PERSISTENT'),
        );
    }

    public function hasPassword(): bool
    {
        return $this->password !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeContext(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'persistent' => $this->persistent,
            'auth' => $this->hasPassword(),
        ];
    }
}
