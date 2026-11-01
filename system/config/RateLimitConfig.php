<?php

declare(strict_types=1);

namespace System\Config;

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
