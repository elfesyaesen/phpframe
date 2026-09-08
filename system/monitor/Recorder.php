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

/**
 * Monitor'ün yazma tarafı.
 *
 * Kaynak projedeki statik `Monitor` sınıfının yerini alır. Fark yalnızca stil
 * değil: statik alanlarda durum tutmadığı için örneklenebilir, bağımlılıkları
 * (depolama, örnekleyici, maskeleyici) test double ile değiştirilebilir ve
 * `flush()` HTTP olmadan doğrudan çağrılıp doğrulanabilir.
 */
final class Recorder implements RecorderInterface
{
    private readonly RequestTrace $trace;

    private bool $flushed = false;

    /**
     * Normalize SQL → görülme sayısı. N+1 tespiti için, saklanan sorgu
     * sınırından BAĞIMSIZ olarak tüm sorgular sayılır.
     *
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

    /**
     * Hâlâ olay toplanıyor mu?
     *
     * `flush()` başladıktan sonra false döner ve bu KRİTİKTİR: monitor'ün
     * kendi INSERT'i enstrümante edilmiş PDO üzerinden gittiği için, guard
     * olmasa kendi yazma sorgusunu "uygulama sorgusu" olarak kaydeder. Sonuç:
     * `monitor_query`'de anlamsız bir satır, `query_count` ile tutarsızlık ve
     * bindings içinde tüm kaydın ikinci bir kopyası. Monitor kendini
     * gözlemlemez — `MONITOR_SKIP_PREFIXES`'in sorgu seviyesindeki karşılığı.
     */
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

        // Sayaçlar HER sorgu için artar; detay saklama sınırlıdır. Böylece
        // patolojik bir döngüde (binlerce sorgu) bellek ve tablo şişmez ama
        // "bu istek 4.000 sorgu attı" bilgisi kaybolmaz.
        $this->trace->queryCount++;
        $this->trace->queryTimeMs += $durationMs;

        $normalized = $this->normalizeSql($sql);
        $this->sqlCounts[$normalized] = ($this->sqlCounts[$normalized] ?? 0) + 1;

        if (count($this->trace->queries) >= $this->maxQueries) {
            return;
        }

        $this->trace->queries[] = [
            'sql' => $sql,
            // Parametreler maskeleme anında temizlenir, saklama anında değil:
            // sır ne kadar kısa süre bellekte durursa o kadar iyi.
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
                // Bir collector'ın patlaması diğerlerini durdurmaz: kısmi iz,
                // hiç iz olmamasından iyidir.
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

            // Yanıtı istemciye şimdi gönder, kaydı sonra yaz. FPM'de bu, izleme
            // maliyetini kullanıcının algıladığı gecikmeden tamamen çıkarır.
            // Yanıt gövdesi collector'lar tarafından yukarıda OKUNDUKTAN sonra
            // çağrılmalı — bu fonksiyon çıktı tamponlarını boşaltır.
            if ($this->canFinishRequestEarly()) {
                fastcgi_finish_request();
            }

            $this->storage->store($this->trace);
        } catch (Throwable $e) {
            // İzleme hiçbir koşulda isteği bozmaz. Ama sessizce yutmak da
            // izlemenin kendisini görünmez kılar — bu yüzden loglanır.
            $this->logger->warning('Monitor flush hatası', [
                'exception'  => $e::class,
                'message'    => $e->getMessage(),
                'request_id' => $this->trace->requestId,
            ]);
        }
    }

    /**
     * Yanıtı erken kapatmak güvenli mi?
     *
     * `fastcgi_finish_request()` çıktıyı gönderip bağlantıyı kapatır. Monitor'ün
     * shutdown fonksiyonu `ExceptionHandler`'ınkinden ÖNCE kayıtlı olduğu için
     * (gerekçe: FatalErrorCollector), bekleyen bir fatal error varken bağlantıyı
     * kapatmak `ExceptionHandler::handleShutdown()`'ın 500 yanıtını üretmesini
     * engeller — istemci bozuk bir 200 alır. Bu durumda erken kapatma yapılmaz;
     * yanıtın doğruluğu, izlemenin gecikme kazancından önce gelir.
     */
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

    /**
     * Aynı sorgunun eşiği aşacak kadar tekrarlanması N+1 kalıbının imzasıdır
     * (ör. 50 kayıt listelenirken her kayıt için ayrı bir ilişki sorgusu).
     * Ek bir sorgu gerektirmez; elde olan sayaçlardan hesaplanır.
     */
    private function detectNPlusOne(): void
    {
        foreach ($this->sqlCounts as $count) {
            if ($count >= $this->nPlusOneThreshold) {
                $this->trace->nPlusOne = true;
                return;
            }
        }
    }

    /**
     * SQL'i "şekline" indirger: literal değerler ve boşluk farkları silinir,
     * böylece yalnızca parametreleri farklı olan sorgular aynı sayılır.
     */
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
     * Stack trace'i args OLMADAN döndürür.
     *
     * `getTrace()` içindeki 'args' alanı çağrı parametrelerini (parola,
     * SECRET_KEY, token) taşır ve asla saklanmamalıdır.
     *
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
