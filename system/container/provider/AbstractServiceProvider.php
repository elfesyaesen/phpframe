<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ServiceProviderInterface;
use System\Runtime\Sapi;

/**
 * Provider'lar için makul varsayılanlar.
 *
 * `provides()` varsayılan olarak boş dizi döner ("beyan etmiyorum") ve
 * `supports()` her ortamda true. Bir provider yalnızca farklı davranması
 * gerektiğinde bunları ezer.
 *
 * `provides()` beyan etmenin faydası: iki provider aynı id'yi tanımlarsa
 * derleme hata verir. Eski imperative bootstrap'ta aynı servisi iki kez
 * kaydetmek sessizce üzerine yazıyordu ve kazananı satır sırası belirliyordu.
 */
abstract class AbstractServiceProvider implements ServiceProviderInterface
{
    public function provides(): array
    {
        return [];
    }

    public function supports(Sapi $sapi): bool
    {
        return true;
    }
}
