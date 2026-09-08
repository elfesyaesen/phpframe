<?php

declare(strict_types=1);

namespace System\Monitor;

/**
 * Bir isteğin biriken izi.
 *
 * Bilinçli olarak MUTABLE ve public alanlı: bu bir birikeç (accumulator),
 * değer nesnesi değil. Collector'lar ve enstrümantasyon noktaları istek
 * boyunca buraya yazar; shutdown'da tek parça hâlinde depolanır. Buna
 * getter/setter çifti yazmak, davranış eklemeden 40 metot üretirdi.
 *
 * `requestId` birincil korelasyon anahtarıdır: bootstrap'ta üretilip
 * `X-Request-ID` header'ı ile istemciye dönen ve tüm log satırlarının
 * context'ine eklenen değerin aynısı. Böylece dashboard'daki bir kayıttan
 * `logs/app.log` satırlarına (ve tersine) geçilebilir.
 */
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
     * Saklanan sorgu detayları. MONITOR_MAX_QUERIES ile sınırlıdır; sayaçlar
     * ($queryCount/$queryTimeMs) sınırdan bağımsız olarak TÜM sorguları sayar,
     * yani patolojik bir döngüde detay kesilse de metrik doğru kalır.
     *
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

    /** Aynı normalize SQL eşiği aşacak kadar tekrarlandı mı? */
    public bool $nPlusOne = false;

    public function hasError(): bool
    {
        return $this->status >= 500 || $this->exceptions !== [];
    }
}
