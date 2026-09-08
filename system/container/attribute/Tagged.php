<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

/**
 * Bir etiketin TÜM üyelerini dizi olarak enjekte eder (DI-plan §20, §22).
 *
 *   public function __construct(
 *       #[Tagged('http.middleware')] private readonly array $middleware,
 *   ) {}
 *
 * Üye listesi derleme zamanında sabitlenir ve sabit bir dizi ifadesine
 * çevrilir: runtime'da etiket sorgusu, filtreleme veya arama YOKTUR
 * (DI-plan §16).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * YALNIZCA PARAMETRE ÜZERİNDE — SINIF ÜZERİNDE DEĞİL, ve bu bilinçli:
 *
 * DI-plan §22 sınıf üzerinde kendini etiketlemeyi de örnekliyor
 * (`#[Tagged('event.listener')] final class UserCreatedListener {}`).
 * Uygulanmadı çünkü bedeli, sağladığı kolaylıktan büyük:
 *
 *   • Sınıfların kendilerini etiketlemesi, "bu etikette kim var?"
 *     sorusunun cevabını DOSYA SİSTEMİ TARAMASINA bağlar. Dev'de container
 *     her istekte kurulduğu için bu tarama her isteğe eklenirdi.
 *   • Tarama sonucu dizin içeriğine bağlı olur; DI-plan §4'ün deterministik
 *     çözümleme hedefi zayıflar.
 *   • Etiket üyeliği, provider'da tek bir yerde görülemez hâle gelir —
 *     "bu pipeline'da ne var?" sorusu kod tabanını taramayı gerektirir.
 *
 * Bunun yerine üyelik provider'da AÇIKÇA bildirilir:
 *
 *   $b->tag([AuthMiddleware::class, RateLimitMiddleware::class], 'http.mw');
 *
 * Bu, SIRAYI da açık kılar — pipeline zincirlerinde sıra anlamsaldır ve
 * dosya tarama sırasına bırakılamaz.
 * ─────────────────────────────────────────────────────────────────────────
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Tagged
{
    public function __construct(
        public string $tag,
    ) {}
}
