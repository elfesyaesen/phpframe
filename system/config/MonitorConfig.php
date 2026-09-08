<?php

declare(strict_types=1);

namespace System\Config;

use SensitiveParameter;

/**
 * Monitor / gözlemlenebilirlik ayarları — 15 `MONITOR_*` sabitinin yerine.
 */
final readonly class MonitorConfig
{
    /**
     * @param list<string> $skipPrefixes
     */
    public function __construct(
        public bool $enabled,
        #[SensitiveParameter] public string $token,
        public MonitorAuthMode $authMode,
        public int $sampleRate,
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
        // `/monitor` skip listesinde OLMAK ZORUNDA: aksi halde dashboard'ın
        // her sayfa görüntülemesi yeni bir kayıt üretir ve monitor kendi
        // kendini izlemeye başlar.
        $skip = $env->list('MONITOR_SKIP_PREFIXES', '/monitor');

        return new self(
            enabled: $env->bool('MONITOR_ENABLED'),
            token: $env->string('MONITOR_TOKEN'),
            authMode: MonitorAuthMode::fromEnvValue($env->string('MONITOR_AUTH_MODE', 'rbac')),
            // Hatalı ve yavaş istekler bu orandan BAĞIMSIZ olarak her zaman
            // saklanır; oran yalnızca "sağlıklı ve hızlı" trafiğe uygulanır.
            sampleRate: $env->int('MONITOR_SAMPLE_RATE', $app->production ? 10 : 100),
            slowMs: $env->float('MONITOR_SLOW_MS', 1000),
            slowQueryMs: $env->float('MONITOR_SLOW_QUERY_MS', 100),
            captureRequestBody: $env->bool('MONITOR_CAPTURE_REQUEST_BODY', true),
            // Yanıt gövdesi yakalamak ob_start() gerektirir; büyük indirmelerde
            // bellek maliyeti oluşur, bu yüzden production'da kapalı.
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

    /**
     * Sorgu enstrümantasyonu etkin mi?
     *
     * `PDO::ATTR_STATEMENT_CLASS` kalıcı bağlantılarla çalışmadığı için
     * `DB_PERSISTENT=true` iken otomatik devre dışı kalır.
     *
     * ÖNEMLİ: bu karar SAF olmak zorunda — bağlantı kurmadan yanıtlanabilmeli
     * ki `app:health` durumu raporlarken DB'ye bağlanmasın.
     */
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
