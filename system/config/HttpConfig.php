<?php

declare(strict_types=1);

namespace System\Config;

/**
 * HTTP katmanı ayarları — `TRUSTED_PROXIES` ve `CORS_ORIGINS` yerine.
 */
final readonly class HttpConfig
{
    /**
     * @param list<string> $trustedProxies
     * @param list<string> $corsOrigins
     */
    public function __construct(
        public array $trustedProxies,
        public array $corsOrigins,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            trustedProxies: $env->list('TRUSTED_PROXIES', '127.0.0.1,::1,localhost'),
            corsOrigins: $env->list('CORS_ORIGINS'),
        );
    }

    public function trustsProxy(string $address): bool
    {
        return in_array($address, $this->trustedProxies, true);
    }

    public function allowsOrigin(string $origin): bool
    {
        return in_array($origin, $this->corsOrigins, true);
    }
}
