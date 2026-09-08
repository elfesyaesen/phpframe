<?php

declare(strict_types=1);

namespace System\Logging;

use System\Logging\Contracts\HandlerInterface;
use System\Logging\Contracts\LoggerInterface;

final class Logger implements LoggerInterface
{
    /** @var HandlerInterface[] */
    private array $handlers = [];

    /**
     * Her log kaydına eklenen kalıcı context (örn. request_id).
     * Korelasyon için bir kez set edilir; her çağrıda tekrarlanmaz.
     *
     * @var array<string, mixed>
     */
    private array $globalContext = [];

    public function addHandler(HandlerInterface $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }

    /**
     * Tüm sonraki log kayıtlarına eklenecek kalıcı context'i birleştirir.
     * Per-call context aynı anahtarı ezebilir.
     *
     * @param array<string, mixed> $context
     */
    public function withContext(array $context): self
    {
        $this->globalContext = [...$this->globalContext, ...$context];
        return $this;
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $record = new LogRecord($level, $message, [...$this->globalContext, ...$context]);

        foreach ($this->handlers as $handler) {
            $handler->handle($record);
        }
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
