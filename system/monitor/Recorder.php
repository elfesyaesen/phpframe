<?php

declare(strict_types=1);

namespace System\Monitor;

use System\Logging\Contracts\LoggerInterface;
use System\Monitor\Contracts\CollectorInterface;
use System\Monitor\Contracts\RecorderInterface;
use System\Monitor\Contracts\SamplerInterface;
use System\Monitor\Contracts\ScrubberInterface;
use System\Monitor\Contracts\StorageInterface;
use Throwable;

final class Recorder implements RecorderInterface
{
    private readonly RequestTrace $trace;

    private bool $flushed = false;

    /**
     * @var array<string, int>
     */
    private array $sqlCounts = [];

    /**
     * @param array<int, CollectorInterface> $collectors
     */
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly SamplerInterface $sampler,
        private readonly ScrubberInterface $scrubber,
        private readonly LoggerInterface $logger,
        private readonly array $collectors,
        private readonly bool $recording,
        string $requestId,
        private readonly int $maxQueries = 100,
        private readonly int $nPlusOneThreshold = 5,
    ) {
        $this->trace = new RequestTrace();
        $this->trace->requestId = $requestId;
    }

    public function isRecording(): bool
    {
        return $this->recording;
    }

    private function isCollecting(): bool
    {
        return $this->recording && !$this->flushed;
    }

    public function trace(): RequestTrace
    {
        return $this->trace;
    }

    public function recordException(Throwable $throwable): void
    {
        if (!$this->isCollecting()) {
            return;
        }

        $this->trace->exceptions[] = [
            'class'   => $throwable::class,
            'message' => $throwable->getMessage(),
            'file'    => $throwable->getFile(),
            'line'    => $throwable->getLine(),
            'trace'   => $this->safeTrace($throwable),
        ];
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function recordQuery(string $sql, array $bindings, float $durationMs): void
    {
        if (!$this->isCollecting()) {
            return;
        }

        $this->trace->queryCount++;
        $this->trace->queryTimeMs += $durationMs;

        $normalized = $this->normalizeSql($sql);
        $this->sqlCounts[$normalized] = ($this->sqlCounts[$normalized] ?? 0) + 1;

        if (count($this->trace->queries) >= $this->maxQueries) {
            return;
        }

        $this->trace->queries[] = [
            'sql' => $sql,

            'bindings'    => $this->scrubber->scrub($bindings),
            'duration_ms' => $durationMs,
        ];
    }

    public function recordCache(string $key, bool $hit): void
    {
        if (!$this->isCollecting()) {
            return;
        }

        if ($hit) {
            $this->trace->cacheHits++;
            return;
        }

        $this->trace->cacheMisses++;
    }

    public function flush(): void
    {
        if ($this->flushed || !$this->recording) {
            return;
        }

        $this->flushed = true;

        try {
            foreach ($this->collectors as $collector) {
                try {
                    $collector->collect($this->trace);
                } catch (Throwable $e) {
                    $this->logger->warning('Monitor collector hatası', [
                        'collector' => $collector->name(),
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                    ]);
                }
            }

            $this->detectNPlusOne();

            if (!$this->sampler->shouldStore($this->trace)) {
                return;
            }

            if ($this->canFinishRequestEarly()) {
                fastcgi_finish_request();
            }

            $this->storage->store($this->trace);
        } catch (Throwable $e) {
            $this->logger->warning('Monitor flush hatası', [
                'exception'  => $e::class,
                'message'    => $e->getMessage(),
                'request_id' => $this->trace->requestId,
            ]);
        }
    }

    private function canFinishRequestEarly(): bool
    {
        if (!function_exists('fastcgi_finish_request')) {
            return false;
        }

        $error = error_get_last();

        if ($error === null) {
            return true;
        }

        return !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    }

    private function detectNPlusOne(): void
    {
        foreach ($this->sqlCounts as $count) {
            if ($count >= $this->nPlusOneThreshold) {
                $this->trace->nPlusOne = true;
                return;
            }
        }
    }

    private function normalizeSql(string $sql): string
    {
        $normalized = preg_replace(
            ['/\'[^\']*\'/', '/"[^"]*"/', '/\b\d+\b/', '/\s+/'],
            ['?', '?', '?', ' '],
            $sql
        );

        return strtolower(trim($normalized ?? $sql));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function safeTrace(Throwable $throwable): array
    {
        return array_map(
            static fn (array $frame): array => [
                'file'     => $frame['file'] ?? null,
                'line'     => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class'    => $frame['class'] ?? null,
            ],
            array_slice($throwable->getTrace(), 0, 15)
        );
    }
}
