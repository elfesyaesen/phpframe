<?php

declare(strict_types=1);

namespace System\Container\Contract;

use System\Runtime\Sapi;

/**
 * Servis tanımlarını bildiren birim.
 *
 * İKİ FAZ AYRIMI bu tasarımın taşıyıcı kararıdır:
 *
 *   register()  → SAF. Yalnızca tanım. Dev'de build-time, `container:compile`
 *                 sırasında compile-time çalışır. Derlenebilir olmasının şartı
 *                 saf olmasıdır.
 *   boot()      → SAF DEĞİL (bkz. BootableProviderInterface). Yan etkiler.
 *                 Asla derlenmez, yalnızca runtime'da çalışır.
 *
 * register() içinde YAPILMAMASI gerekenler:
 *   • servis çözmek ($c->get(...))
 *   • $_SERVER / $_GET / php://input okumak
 *   • header(), ob_start(), register_shutdown_function()
 *   • dosya/socket açmak, DB'ye bağlanmak
 *
 * Bu kural sayesinde bootstrap'ın eski "sıra kritik" sorunları yapısal olarak
 * ortadan kalkar: tüm register() çağrıları herhangi bir get()'ten önce
 * tamamlandığı için "binding henüz kaydedilmemişti" durumu temsil edilemez.
 */
interface ServiceProviderInterface
{
    /**
     * Tanımları builder'a yazar. Saf olmak ZORUNDA (yukarıdaki listeye bak).
     */
    public function register(ContainerBuilderInterface $builder): void;

    /**
     * Bu provider'ın tanımladığı servis id'leri — compile-time manifest.
     *
     * İki provider aynı id'yi bildirirse bu HATA'dır: hangisinin kazandığı
     * liste sırasına bağlı olurdu, bu da deterministik değildir (DI-plan §4).
     * Bugünkü imperative bootstrap'ta aynı id'yi iki kez singleton() etmek
     * sessizce üzerine yazıyor; bu manifest o hata sınıfını görünür kılar.
     *
     * Boş dizi "beyan etmiyorum" demektir (çakışma kontrolüne girmez).
     *
     * @return list<class-string>
     */
    public function provides(): array;

    /**
     * Bu provider verilen ortamda katılıyor mu?
     *
     * Örnek: RoutingProvider CLI'da false döner (her CLI çağrısında attribute
     * taraması yapmanın anlamı yok); ConsoleProvider yalnızca CLI'da true.
     */
    public function supports(Sapi $sapi): bool;
}
