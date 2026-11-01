<?php

declare(strict_types=1);

namespace System\Monitor;

final class RequestTrace
{
    public string $requestId = '';
    public string $method = '';
    public string $uri = '';
    public ?string $routeName = null;
    public int $status = 0;
    public float $durationMs = 0.0;
    public int $memoryKb = 0;
    public ?string $ip = null;
    public ?string $userUuid = null;

    /** @var array<string, string> */
    public array $headers = [];

    public ?string $requestBody = null;
    public ?string $responseBody = null;

    /**
     * @var array<int, array{sql: string, bindings: array<int|string, mixed>, duration_ms: float}>
     */
    public array $queries = [];

    public int $queryCount = 0;
    public float $queryTimeMs = 0.0;

    /**
     * @var array<int, array{class: string, message: string, file: string, line: int, trace: array<int, array<string, mixed>>}>
     */
    public array $exceptions = [];

    public int $cacheHits = 0;
    public int $cacheMisses = 0;

    public bool $nPlusOne = false;

    public function hasError(): bool
    {
        return $this->status >= 500 || $this->exceptions !== [];
    }
}
