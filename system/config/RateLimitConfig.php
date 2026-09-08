<?php

declare(strict_types=1);

namespace System\Config;

/**
 * Rate limit varsayılanları.
 *
 * `RateLimitMiddleware` içindeki `DEFAULT_MAX_ATTEMPTS = 60` /
 * `DEFAULT_DECAY_SECONDS = 60` sabitlerinin yerine geçer. Route başına
 * `rate_limit:100,120` biçimindeki override'lar korunur; bu obje yalnızca
 * parametre verilmediğinde kullanılan varsayılanı taşır.
 *
 * Sabitleri config'e taşımanın somut faydası: production'da limiti
 * değiştirmek kod dağıtımı değil `.env` değişikliği olur.
 */
final readonly class RateLimitConfig
{
    public function __construct(
        public int $maxAttempts,
        public int $decaySeconds,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            maxAttempts: $env->int('RATE_LIMIT_MAX_ATTEMPTS', 60),
            decaySeconds: $env->int('RATE_LIMIT_DECAY_SECONDS', 60),
        );
    }
}
