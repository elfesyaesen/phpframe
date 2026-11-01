<?php

declare(strict_types=1);

namespace System\Config;

use SensitiveParameter;

final readonly class MailConfig
{
    public function __construct(
        public string $protocol,
        public string $host,
        public int $port,
        public string $username,
        #[SensitiveParameter] public string $password,
        public string $title,
        public bool $verifyTls = true,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            protocol: $env->string('SMTP_PROTOCOL', 'tls'),
            host: $env->string('SMTP_HOST'),
            port: $env->int('SMTP_PORT', 587),
            username: $env->string('SMTP_USERNAME'),
            password: $env->string('SMTP_PASSWORD'),
            title: $env->string('SMTP_TITLE'),
            verifyTls: $env->bool('SMTP_VERIFY_TLS', true),
        );
    }

    public function isConfigured(): bool
    {
        return $this->host !== '';
    }
}
