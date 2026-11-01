<?php

declare(strict_types=1);

namespace System\Monitor;

use System\Monitor\Contracts\RecorderInterface;
use Throwable;

final class NullRecorder implements RecorderInterface
{
    private readonly RequestTrace $trace;

    public function __construct()
    {
        $this->trace = new RequestTrace();
    }

    public function isRecording(): bool
    {
        return false;
    }

    public function trace(): RequestTrace
    {
        return $this->trace;
    }

    public function recordException(Throwable $throwable): void {}

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function recordQuery(string $sql, array $bindings, float $durationMs): void {}

    public function recordCache(string $key, bool $hit): void {}

    public function flush(): void {}
}
