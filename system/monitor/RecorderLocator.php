<?php

declare(strict_types=1);

namespace System\Monitor;

use Closure;
use System\Monitor\Contracts\RecorderInterface;
use Throwable;

final class RecorderLocator implements RecorderInterface
{
    /**
     * @param Closure(): RecorderInterface $resolver
     */
    public function __construct(
        private readonly Closure $resolver,
    ) {}

    private function active(): ?RecorderInterface
    {
        try {
            $recorder = ($this->resolver)();
        } catch (Throwable) {
            return null;
        }

        return $recorder === $this ? null : $recorder;
    }

    public function isRecording(): bool
    {
        return $this->active()?->isRecording() ?? false;
    }

    public function trace(): RequestTrace
    {
        return $this->active()?->trace() ?? new RequestTrace();
    }

    public function recordException(Throwable $throwable): void
    {
        $this->active()?->recordException($throwable);
    }

    public function recordQuery(string $sql, array $bindings, float $durationMs): void
    {
        $this->active()?->recordQuery($sql, $bindings, $durationMs);
    }

    public function recordCache(string $key, bool $hit): void
    {
        $this->active()?->recordCache($key, $hit);
    }

    public function flush(): void
    {
        $this->active()?->flush();
    }
}
