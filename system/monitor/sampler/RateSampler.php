<?php

declare(strict_types=1);

namespace System\Monitor\Sampler;

use System\Monitor\Contracts\SamplerInterface;
use System\Monitor\RequestTrace;

/**
 * Oran tabanlı örnekleyici, "ilginç olan her zaman saklanır" kuralıyla.
 *
 * Kaynak proje HER isteği saklıyordu; uzak bir veritabanında bu, istek başına
 * fazladan bir round-trip ve sınırsız büyüyen bir tablo demekti. Buradaki
 * kural sırası, hacmi düşürürken teşhis değeri yüksek istekleri hiç
 * kaybetmemek üzere kurulmuştur.
 */
final class RateSampler implements SamplerInterface
{
    /**
     * Bot/güvenlik taraması desenleri.
     *
     * Kaynak projede bu liste yazılmış ama yoruma alınmıştı; burada aktiftir.
     * Bu istekler uygulama hakkında hiçbir şey söylemez, sadece log tablosunu
     * doldurur.
     */
    private const SCAN_PATTERNS = [
        '/.git', '/.env', '/.aws', '/wp-', '/wordpress',
        '/phpmyadmin', '/adminer', '/actuator', '/vendor/phpunit',
        '/.well-known/traffic-advice', '/cgi-bin',
    ];

    /**
     * @param int                $sampleRate   0-100 arası yüzde. 100 = her istek.
     * @param float              $slowMs       Bu süreyi aşan istek her zaman saklanır.
     * @param array<int, string> $skipPrefixes Bu prefix'lerle başlayan yollar hiç saklanmaz.
     */
    public function __construct(
        private readonly int $sampleRate,
        private readonly float $slowMs,
        private readonly array $skipPrefixes = [],
    ) {}

    public function shouldStore(RequestTrace $trace): bool
    {
        // 1) Atlanacak yollar — HER ŞEYDEN ÖNCE. Monitor dashboard'ının kendi
        //    istekleri kaydedilirse her sayfa görüntüleme yeni kayıt üretir ve
        //    izleme kendi gürültüsünü izlemeye başlar.
        foreach ($this->skipPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($trace->uri, $prefix)) {
                return false;
            }
        }

        // 2) Bot taraması — hata (404) üretse bile saklanmaz; aksi halde tarama
        //    trafiği "hatalar her zaman saklanır" kuralını sömürerek tabloyu
        //    doldurabilirdi.
        $lowerUri = strtolower($trace->uri);
        foreach (self::SCAN_PATTERNS as $pattern) {
            if (str_contains($lowerUri, $pattern)) {
                return false;
            }
        }

        // 3) Hatalar ve exception'lar: asla örneklenmez. Nadir oldukları için
        //    hacme etkileri yok, teşhis değerleri en yüksek.
        if ($trace->hasError()) {
            return true;
        }

        // 4) Yavaş istekler: performans araştırmasının tüm konusu bunlar.
        if ($trace->durationMs >= $this->slowMs) {
            return true;
        }

        // 5) Kalan "sağlıklı ve hızlı" trafiğe oran uygula.
        if ($this->sampleRate >= 100) {
            return true;
        }

        if ($this->sampleRate <= 0) {
            return false;
        }

        return random_int(1, 100) <= $this->sampleRate;
    }
}
