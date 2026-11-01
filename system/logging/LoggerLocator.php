<?php

declare(strict_types=1);

namespace System\Logging;

use Closure;
use System\Logging\Contracts\LoggerInterface;
use Throwable;

final class LoggerLocator implements LoggerInterface
{
    /**
     * @param Closure(): LoggerInterface $resolver
     * @param Logger $fallback
     */
    public function __construct(
        private readonly Closure $resolver,
        private readonly Logger $fallback,
    ) {}

    private function active(): LoggerInterface
    {
        try {
            $logger = ($this->resolver)();
        } catch (Throwable) {
            return $this->fallback;
        }

        return $logger === $this ? $this->fallback : $logger;
    }

    public function withContext(array $context): self
    {
        return $this;
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $this->active()->log($level, $message, $context);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->active()->emergency($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->active()->alert($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->active()->critical($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->active()->error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->active()->warning($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->active()->notice($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->active()->info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->active()->debug($message, $context);
    }
}
