<?php

declare(strict_types=1);

namespace System\Config;

/**
 * Log dosyası ayarları — `LOG_*` sabitlerinin yerine.
 */
final readonly class LogConfig
{
    public function __construct(
        public string $rotationPeriod,
        public int $retention,
        public int $maxFileSizeMb,
        public string $directory,
    ) {}

    public static function fromEnv(EnvReader $env, AppConfig $app): self
    {
        return new self(
            rotationPeriod: $env->string('LOG_ROTATION_PERIOD', 'daily'),
            retention: $env->int('LOG_RETENTION', 15),
            maxFileSizeMb: $env->int('LOG_MAX_FILE_SIZE_MB', 10),
            directory: $app->path('logs'),
        );
    }

    public function file(string $name): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . $name;
    }

    public function applicationLog(): string
    {
        return $this->file('app.log');
    }

    public function errorLog(): string
    {
        return $this->file('errors.log');
    }
}
