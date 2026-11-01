<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use System\Monitor\RequestTrace;
use Throwable;

interface RecorderInterface
{
    public function isRecording(): bool;

    public function trace(): RequestTrace;

    public function recordException(Throwable $throwable): void;

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function recordQuery(string $sql, array $bindings, float $durationMs): void;

    public function recordCache(string $key, bool $hit): void;

    public function flush(): void;
}
