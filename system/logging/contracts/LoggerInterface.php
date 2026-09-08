<?php

declare(strict_types=1);

namespace System\Logging\Contracts;

use System\Logging\LogLevel;

interface LoggerInterface
{
    /**
     * Tüm sonraki log kayıtlarına eklenecek kalıcı context'i birleştirir
     * (örn. request_id korelasyonu).
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self;

    public function log(LogLevel $level, string $message, array $context = []): void;

    public function emergency(string $message, array $context = []): void;

    public function alert(string $message, array $context = []): void;

    public function critical(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;

    public function warning(string $message, array $context = []): void;

    public function notice(string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function debug(string $message, array $context = []): void;
}
