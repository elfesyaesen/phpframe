<?php

declare(strict_types=1);

namespace System\Config;

/**
 * Dış servis (upstream) ayarları — `PRODUCTS_UPSTREAM_*` yerine.
 */
final readonly class UpstreamConfig
{
    public function __construct(
        public string $productsUrl,
        public int $productsTimeout,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            productsUrl: $env->string('PRODUCTS_UPSTREAM_URL'),
            productsTimeout: $env->int('PRODUCTS_UPSTREAM_TIMEOUT', 5),
        );
    }

    public function hasProductsUpstream(): bool
    {
        return $this->productsUrl !== '';
    }
}
