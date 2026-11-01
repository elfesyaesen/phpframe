<?php

declare(strict_types=1);

namespace System\Config;

use SensitiveParameter;

final readonly class AuthConfig
{
    public function __construct(
        #[SensitiveParameter] public string $secretKey,
        public int $accessTokenExpire,
        public int $refreshTokenExpire,
        public int $passwordResetExpire = 1800,
        public string $passwordResetUrl = 'reset-password',
        public int $passwordResetMaxAttempts = 5,
    ) {}

    public function resetLink(AppConfig $app, string $token): string
    {
        $query = (str_contains($this->passwordResetUrl, '?') ? '&' : '?') . 'token=' . $token;

        $isAbsolute = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $this->passwordResetUrl) === 1;

        return $isAbsolute
            ? $this->passwordResetUrl . $query
            : $app->url($this->passwordResetUrl . $query);
    }

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            secretKey: $env->string('SECRET_KEY'),
            accessTokenExpire: $env->int('ACCESS_TOKEN_EXPIRE', 3600),
            refreshTokenExpire: $env->int('REFRESH_TOKEN_EXPIRE', 604800),
            passwordResetExpire: $env->int('PASSWORD_RESET_EXPIRE', 1800),
            passwordResetUrl: $env->string('PASSWORD_RESET_URL', 'reset-password'),
            passwordResetMaxAttempts: $env->int('PASSWORD_RESET_MAX_ATTEMPTS', 5),
        );
    }

    public function hasStrongSecret(): bool
    {
        return strlen($this->secretKey) >= 32;
    }
}
