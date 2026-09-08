<?php

declare(strict_types=1);

namespace Api\Provider;

use Api\Security\Gate;
use Api\Services\AuthService;
use Api\Services\AvatarService;
use Api\Services\LocaleResolver;
use Api\Services\MailService;
use Api\Services\PermissionService;
use Api\Services\RoleService;
use Api\Services\Translator;
use Api\Services\UserService;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Container\Provider\AbstractServiceProvider;
use System\Http\Contract\LocaleProviderInterface;
use System\Security\Contract\GateInterface;
use System\Translation\Contract\TranslatorInterface;

/**
 * Uygulama (api) modülü servisleri.
 *
 * Framework provider'larından SONRA çalışır: uygulama katmanı framework
 * arayüzlerini somut sınıflara bağlar (DI-plan §11) ve bağımlılık yönü
 * doğru kalır — `System\` asla `Api\`'yi bilmez.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * YERELLEŞTİRME KAYITLARI BURAYA TAŞINDI.
 *
 * `LocaleResolver` ve `Translator` somut `Api\Services\*` sınıflarıdır ama
 * kayıtları `System\Container\Provider\LocalizationProvider` içindeydi —
 * yani framework provider'ı uygulama sınıflarını `use` ediyordu. Bu, tam da
 * yukarıdaki paragrafın yasakladığı yön ihlaliydi ve `api/`'yi bağımsız bir
 * modül olarak çıkarmayı imkânsız kılıyordu.
 *
 * Framework tarafı yalnızca arayüzleri görür: `Response` Content-Language
 * için `LocaleProviderInterface`, `BaseController`/`Validator` çeviri için
 * `TranslatorInterface` alır. Alias'lar burada, uygulama katmanında kurulur.
 *
 * Kayıt SIRASI önemsizdir: tüm `register()` çağrıları herhangi bir `get()`
 * öncesinde tamamlanır (bkz. Kernel::create docblock'u).
 * ─────────────────────────────────────────────────────────────────────────
 */
final class ApiProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // Gate SCOPED: scoped Request'e (aktif kullanıcı) bağımlı. Singleton
        // olsaydı bir worker'ın gördüğü 2. istek 1. isteğin yetkileriyle
        // değerlendirilirdi — kod tabanındaki en tehlikeli scope ihlali.
        $builder->scoped(Gate::class);

        // Framework tarafı somut Gate'i değil arayüzü bilir.
        $builder->alias(GateInterface::class, Gate::class);

        // AuthService SCOPED — eskiden `singleton` idi.
        //
        // Değişimin sebebi: `bearer()` artık header'ı enjekte edilen scoped
        // `Request`'ten okuyor (önce çıplak `getallheaders()` çağırıyordu).
        // Bağımlılık artık imzada göründüğü için singleton bırakmak gerçek
        // bir Singleton→Scoped ihlali olurdu ve `container:validate` haklı
        // olarak reddederdi. Eski hâlde ihlal aynı ölçüde vardı, sadece
        // container'dan GİZLİYDİ.
        $builder->scoped(AuthService::class);
        $builder->scoped(MailService::class);

        // Domain servisleri — SCOPED, çünkü hepsi scoped `TranslatorInterface`
        // alır (kullanıcıya giden mesajları aktif locale ile üretirler).
        //
        // Açık bind ZORUNLU: `RootCollector::allowedPrefixes()` yalnızca
        // `System\` ve modül öneklerini örtük autowire'a açar. Buraya
        // eklenmeyen bir servis `container:validate`'te hata verir — sessizce
        // çalışmaya devam etmez.
        $builder->scoped(AvatarService::class);
        $builder->scoped(PermissionService::class);
        $builder->scoped(RoleService::class);
        $builder->scoped(UserService::class);

        // ── Yerelleştirme ──────────────────────────────────────────────
        //
        // İkisi de SCOPED: `LocaleResolver` scoped `Request`'e bağımlı
        // (Accept-Language başlığını okur), `Translator` da ona bağımlı.
        // Singleton olsalardı bir worker'ın gördüğü ilk isteğin dili sonraki
        // tüm isteklere uygulanırdı — Türkçe isteyen bir istemci, önceki
        // istek İngilizce ise İngilizce yanıt alırdı.
        $builder->scoped(LocaleResolver::class);
        $builder->alias(LocaleProviderInterface::class, LocaleResolver::class);

        // Translator `string $bundlePath` alır — primitive, autowire edilemez
        // (DI-plan §10). Yol AppConfig'ten geldiği için factory.
        $builder->factory(
            Translator::class,
            [self::class, 'translator'],
            Lifetime::SCOPED,
            dependsOn: [LocaleResolver::class, AppConfig::class],
        );

        $builder->alias(TranslatorInterface::class, Translator::class);
    }

    public function provides(): array
    {
        return [
            Gate::class,
            GateInterface::class,
            AuthService::class,
            AvatarService::class,
            MailService::class,
            PermissionService::class,
            RoleService::class,
            UserService::class,
            LocaleResolver::class,
            LocaleProviderInterface::class,
            Translator::class,
            TranslatorInterface::class,
        ];
    }

    // ── Factory ────────────────────────────────────────────────────

    public static function translator(PsrContainerInterface $container): Translator
    {
        return new Translator(
            $container->get(LocaleResolver::class),
            $container->get(AppConfig::class)->path('lang'),
        );
    }
}
