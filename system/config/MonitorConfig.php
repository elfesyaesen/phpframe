<?php

declare(strict_types=1);

namespace System\Config;

use SensitiveParameter;

final readonly class MonitorConfig
{
    /**
     * @param list<string> $skipPrefixes
     */
    public function __construct(
        public bool $enabled,
        #[SensitiveParameter] public string $token,
        public float $slowMs,
        public float $slowQueryMs,
        public bool $captureRequestBody,
        public bool $captureResponseBody,
        public int $maxBodyBytes,
        public int $maxQueries,
        public int $nPlusOneThreshold,
        public bool $recordQueries,
        public bool $recordCache,
        public int $retentionDays,
        public array $skipPrefixes,
    ) {}

    public static function fromEnv(EnvReader $env, AppConfig $app): self
    {
        $skip = $env->list('MONITOR_SKIP_PREFIXES', '/monitor');

        return new self(
            enabled: $env->bool('MONITOR_ENABLED'),
            token: $env->string('MONITOR_TOKEN'),
            slowMs: $env->float('MONITOR_SLOW_MS', 1000),
            slowQueryMs: $env->float('MONITOR_SLOW_QUERY_MS', 100),
            captureRequestBody: $env->bool('MONITOR_CAPTURE_REQUEST_BODY', true),

            captureResponseBody: $env->bool('MONITOR_CAPTURE_RESPONSE_BODY', !$app->production),
            maxBodyBytes: $env->int('MONITOR_MAX_BODY_BYTES', 65535),
            maxQueries: $env->int('MONITOR_MAX_QUERIES', 100),
            nPlusOneThreshold: $env->int('MONITOR_N_PLUS_ONE_THRESHOLD', 5),
            recordQueries: $env->bool('MONITOR_RECORD_QUERIES', true),
            recordCache: $env->bool('MONITOR_RECORD_CACHE', true),
            retentionDays: $env->int('MONITOR_RETENTION_DAYS', 7),
            skipPrefixes: $skip === [] ? ['/monitor'] : $skip,
        );
    }

    public function instrumentsQueries(DatabaseConfig $database): bool
    {
        return $this->enabled
            && $this->recordQueries
            && !$database->persistent;
    }

    public function recordsCache(): bool
    {
        return $this->enabled && $this->recordCache;
    }
}
