<?php

declare(strict_types=1);

namespace System\Monitor;

use System\Monitor\Contracts\RecorderInterface;
use Throwable;

/**
 * Hiçbir şey kaydetmeyen Recorder (Null Object).
 *
 * `Database` artık `RecorderInterface`'i ZORUNLU alır — enstrümantasyonun
 * opsiyonel bir eklenti değil, bağlantı kurulumunun parçası olduğunu tipte
 * göstermek için. Container'ın olmadığı yerlerde (CLI komutları: `migrate`,
 * `app:health`, `monitor:purge`) bağımlılık bununla karşılanır.
 *
 * `isRecording()` false döndüğü için `Database` düz `PDO` kurar; hiçbir
 * enstrümantasyon devreye girmez.
 */
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
