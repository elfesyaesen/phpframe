<?php

declare(strict_types=1);

namespace System\Http\Contract;

/**
 * Aktif dil kodunu sağlayan servis.
 *
 * NEDEN BU ARAYÜZ VAR: `Response`, `Content-Language` başlığı için aktif
 * locale'e ihtiyaç duyuyordu ve bunu container'dan `Api\Services\LocaleResolver`
 * çekerek elde ediyordu. İki sorun:
 *
 *   1. Framework kodu (`System\Http`) uygulama modülüne (`Api\`) bağımlı hâle
 *      geliyordu — api modülü olmayan bir kurulumda anlamsız.
 *   2. Container'dan servis çekmek DI-plan §37'nin yasakladığı service
 *      locator'dır: Response'un gerçek bağımlılığı imzasında görünmez,
 *      dolayısıyla derleyici onu doğrulayamaz.
 *
 * Arayüz ikisini de çözer: bağımlılık constructor'da bildirilir, somut
 * uygulama uygulama katmanında bind edilir (DI-plan §11).
 */
interface LocaleProviderInterface
{
    /**
     * Aktif dil kodu (örn. 'tr', 'en'). Boş string döndürmemeli.
     */
    public function locale(): string;
}
