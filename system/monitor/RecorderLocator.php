<?php

declare(strict_types=1);

namespace System\Monitor;

use Closure;
use System\Monitor\Contracts\RecorderInterface;
use Throwable;

/**
 * Aktif scope'un Recorder'ına delege eden SINGLETON cephe.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN VAR — mevcut ve görünmez bir cross-request veri sızıntısı:
 *
 * `Database` singleton olmak zorunda (worker başına tek PDO, DB_PERSISTENT),
 * ama `Recorder` scoped olmak zorunda (RequestTrace, sorgu sayaçları, flush
 * durumu per-request mutable state'tir).
 *
 * Singleton → Scoped bağımlılığı DI-plan §19'un yasakladığı kenardır ve
 * burada bir lint uyarısından çok daha kötüsüdür: `PDO::ATTR_STATEMENT_CLASS`
 * recorder'ı BAĞLANTI KURULURKEN gömer. Yani aynı PHP-FPM worker'ındaki
 * 2. istek, sorgularını 1. isteğin trace'ine yazar. Bugün görünmemesinin tek
 * nedeni Recorder'ın da process singleton olması — yani hata, tam olarak
 * Recorder doğru şekilde scoped yapıldığında ortaya çıkardı.
 *
 * Bu sınıf ince bir yönlendirme katmanı ekleyerek kenarı ortadan kaldırır:
 * Database singleton bir LOCATOR tutar, o locator her çağrıda AKTİF scope'un
 * recorder'ını çözer. Sonuç:
 *   • Database → Recorder graf kenarı yok → §19 ihlali yok
 *   • her sorgu doğru isteğin trace'ine gider
 *   • bağlantı hâlâ worker ömrü boyunca paylaşılır
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Arayüz sözleşmesi korunur: hiçbir metot exception fırlatmaz. Aktif scope
 * yoksa (CLI, shutdown sonrası) sessizce "kayıt yok" davranır — izleme,
 * izlenen isteği bozmamalıdır.
 */
final class RecorderLocator implements RecorderInterface
{
    /**
     * @param Closure(): RecorderInterface $resolver Aktif scope'un recorder'ını çözer
     */
    public function __construct(
        private readonly Closure $resolver,
    ) {}

    /**
     * Aktif recorder, çözülemezse null.
     *
     * Çözümleme HATA VEREBİLİR (scope yok, scope kapatılmış) ve bu meşrudur:
     * CLI'da veya scope teardown sonrasında kayıt yapılacak bir istek yoktur.
     */
    private function active(): ?RecorderInterface
    {
        try {
            $recorder = ($this->resolver)();
        } catch (Throwable) {
            return null;
        }

        // Kendine delege etmeye karşı koruma: locator yanlışlıkla
        // RecorderInterface bağlaması olarak kaydedilirse sonsuz özyineleme
        // olurdu.
        return $recorder === $this ? null : $recorder;
    }

    public function isRecording(): bool
    {
        return $this->active()?->isRecording() ?? false;
    }

    public function trace(): RequestTrace
    {
        // Aktif recorder yoksa atılabilir bir trace döndürülür: çağıran
        // (collector) null kontrolü yapmak zorunda kalmasın.
        return $this->active()?->trace() ?? new RequestTrace();
    }

    public function recordException(Throwable $throwable): void
    {
        $this->active()?->recordException($throwable);
    }

    public function recordQuery(string $sql, array $bindings, float $durationMs): void
    {
        $this->active()?->recordQuery($sql, $bindings, $durationMs);
    }

    public function recordCache(string $key, bool $hit): void
    {
        $this->active()?->recordCache($key, $hit);
    }

    public function flush(): void
    {
        $this->active()?->flush();
    }
}
