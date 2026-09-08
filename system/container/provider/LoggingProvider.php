<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Config\LogConfig;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Lifetime\Lifetime;
use System\Logging\Contracts\LoggerInterface;
use System\Logging\Handlers\FileHandler;
use System\Logging\LogLevel;
use System\Logging\Logger;
use System\Logging\LoggerLocator;
use System\Runtime\RequestId;

/**
 * Loglama.
 *
 * REQUEST_ID İÇİN ÖNEMLİ TASARIM DEĞİŞİKLİĞİ:
 *
 * Eski bootstrap `$logger->withContext(['request_id' => $requestId])` çağırıyordu,
 * yani SINGLETON bir logger'a PER-REQUEST veri yazıyordu. PHP-FPM'de bir worker
 * ardışık birçok istek gördüğü için 2. isteğin log satırları 1. isteğin
 * request_id'sini taşırdı — log ↔ monitor korelasyonu sessizce yalan söyler.
 * Dev'de görünmez (worker tek istek görür), production'da teşhisi bozar.
 *
 * Çözüm: taban logger singleton kalır (dosya tanıtıcıları paylaşılır), ama
 * `LoggerInterface` SCOPED bir dekoratöre bağlanır: her istek kendi
 * request_id context'ini taşıyan kendi sarmalayıcısını alır.
 */
final class LoggingProvider extends AbstractServiceProvider implements BootableProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // Taban logger: dosya handler'ları singleton — rotasyon durumu ve
        // dosya tanıtıcıları worker ömrü boyunca paylaşılmalı.
        $builder->factory(
            Logger::class,
            [self::class, 'baseLogger'],
            Lifetime::SINGLETON,
            dependsOn: [LogConfig::class, AppConfig::class],
        );

        // Uygulamanın gördüğü logger: SCOPED, request_id taşır.
        $builder->factory(
            LoggerInterface::class,
            [self::class, 'requestLogger'],
            Lifetime::SCOPED,
            dependsOn: [Logger::class, RequestId::class],
        );

        // SINGLETON servislerin kullanacağı logger cephesi.
        //
        // Bir singleton'a scoped LoggerInterface enjekte etmek DI-plan §19'un
        // yasakladığı kenardır ve pratikte 1. isteğin request_id'sini sonsuza
        // pinler. Locator her çağrıda aktif scope'un logger'ını çözer,
        // scope yoksa taban logger'a düşer (bkz. LoggerLocator).
        $builder->factory(
            LoggerLocator::class,
            [self::class, 'loggerLocator'],
            Lifetime::SINGLETON,
            dependsOn: [Logger::class],
        );
    }

    public function provides(): array
    {
        return [Logger::class, LoggerInterface::class, LoggerLocator::class];
    }

    /**
     * Boot aşamasında yapılacak bir yan etki YOK.
     *
     * X-Request-ID başlığı burada gönderilemez: boot, istek scope'u
     * açılmadan çalışır ve kimlik scope'a bağlıdır. Başlık gönderimi giriş
     * noktasının işi (bkz. Kernel::beginRequestScope) — gövdeden önce
     * çıkması gerektiği için de oraya aittir.
     */
    public function boot(ContainerInterface $container): void
    {
    }

    // ── Factory'ler ────────────────────────────────────────────────

    public static function baseLogger(PsrContainerInterface $container): Logger
    {
        $config = $container->get(LogConfig::class);
        $app = $container->get(AppConfig::class);

        $logger = new Logger();

        // app.log — production'da INFO, dev'de DEBUG.
        $logger->addHandler(new FileHandler(
            $config->applicationLog(),
            $app->production ? LogLevel::INFO : LogLevel::DEBUG,
            null,
            $config->maxFileSizeMb,
            $config->retention,
            $config->rotationPeriod,
        ));

        // errors.log — yalnızca ERROR ve üstü. Ayrı dosya olması kasıtlı:
        // bir olayda incelenecek ilk yer gürültüsüz olmalı.
        $logger->addHandler(new FileHandler(
            $config->errorLog(),
            LogLevel::ERROR,
            null,
            $config->maxFileSizeMb,
            $config->retention,
            $config->rotationPeriod,
        ));

        return $logger;
    }

    /**
     * İstek kapsamlı logger: taban logger'ın request_id context'i eklenmiş
     * kopyası.
     *
     * `clone` ZORUNLU: `Logger::withContext()` `$this->globalContext`'i
     * değiştirip `$this` döndürür (mutating fluent builder). Klonlanmadan
     * çağrılsaydı singleton her isteğin request_id'sini ÜST ÜSTE biriktirir
     * ve log satırları birden fazla — hepsi yanlış — kimlik taşırdı.
     *
     * Klon handler'ları referansla paylaşır; bu istenen davranış: handler'lar
     * dosya yolu + seviye tutar, rotasyon durumu paylaşılmalı.
     */
    public static function requestLogger(PsrContainerInterface $container): LoggerInterface
    {
        $base = $container->get(Logger::class);
        $requestId = $container->get(RequestId::class);

        return (clone $base)->withContext(['request_id' => $requestId->value]);
    }

    public static function loggerLocator(PsrContainerInterface $container): LoggerLocator
    {
        return new LoggerLocator(
            static fn(): LoggerInterface => $container->get(LoggerInterface::class),
            $container->get(Logger::class),
        );
    }
}
