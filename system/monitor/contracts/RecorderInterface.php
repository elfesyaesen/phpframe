<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use System\Monitor\RequestTrace;
use Throwable;

/**
 * Monitor'ün yazma-tarafı cephesi.
 *
 * İstek ömrü boyunca olayları bellekte `RequestTrace` üzerinde biriktirir;
 * `flush()` shutdown'da TAM OLARAK BİR KEZ çalışıp tek bir depolama çağrısı
 * yapar. Sorgu başına ayrı INSERT atan kaynak tasarımın aksine, uzak bir
 * veritabanına istek başına yalnızca bir round-trip ödenir.
 *
 * Sözleşme: bu arayüzün HİÇBİR metodu exception fırlatmaz. İzleme, izlenen
 * isteği bozmamalıdır.
 */
interface RecorderInterface
{
    /**
     * Monitor bu istek için aktif mi? (config kapalıysa false)
     *
     * Enstrümantasyon noktaları (PDO/cache sarmalayıcıları) bu bayrağa bakarak
     * hiç devreye girmez; kapalıyken maliyet sıfırdır.
     */
    public function isRecording(): bool;

    /**
     * Bu isteğin biriken izi. Collector'lar doğrudan bunu doldurur.
     */
    public function trace(): RequestTrace;

    public function recordException(Throwable $throwable): void;

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function recordQuery(string $sql, array $bindings, float $durationMs): void;

    public function recordCache(string $key, bool $hit): void;

    /**
     * Collector'ları çalıştırır, örnekleme kararını verir ve saklar.
     * Idempotent: ikinci çağrı sessizce yok sayılır.
     */
    public function flush(): void;
}
