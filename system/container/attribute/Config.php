<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

/**
 * `literal()` ile kaydedilmiş bir yapılandırma değerini enjekte eder
 * (DI-plan §22).
 *
 *   public function __construct(
 *       #[Config('lang.path')] private readonly string $langPath,
 *   ) {}
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NE ZAMAN KULLANILIR — VE NE ZAMAN KULLANILMAZ:
 *
 * DI-plan §10 primitive injection'ı reddeder ve TYPED CONFIG OBJESİ önerir;
 * bu, kod tabanının varsayılan yolu olmaya devam eder
 * (`__construct(DatabaseConfig $config)`).
 *
 * `Config` attribute'u yalnızca typed config objesi kurmanın aşırı geldiği
 * TEK BİR skaler için vardır — örneğin bir dizin yolu. Onu attribute'suz
 * yazmanın tek alternatifi factory yazmaktır; bu, tek bir string için
 * gereksiz tören olur.
 *
 * Bir sınıf İKİ VEYA DAHA FAZLA skaler istiyorsa attribute yerine typed
 * config objesi yazın: o zaman değerler bir arada anlam kazanır, doğrulanır
 * ve tek yerden gelir.
 * ─────────────────────────────────────────────────────────────────────────
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Config
{
    /**
     * @param string $key `literal()` ile kaydedilmiş id
     */
    public function __construct(
        public string $key,
    ) {}
}
