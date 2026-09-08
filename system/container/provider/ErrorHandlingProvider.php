<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Lifetime\Lifetime;
use System\Logging\LoggerLocator;
use System\Logging\ErrorRenderer\JsonRenderer;
use System\Logging\ExceptionHandler;
use System\Monitor\Contracts\ScrubberInterface;
use System\Monitor\RecorderLocator;
use System\Runtime\ShutdownRegistry;

/**
 * Global hata yakalama.
 *
 * ESKİ SIRA KISITI VE NASIL ÇÖZÜLDÜĞÜ:
 *
 * Eski bootstrap'ta `MonitorBootstrapper::boot()` mutlaka
 * `ExceptionHandler::register()`'dan ÖNCE çağrılmak zorundaydı, çünkü ikisi
 * ayrı `register_shutdown_function` çağrılarıydı ve ExceptionHandler'ın
 * shutdown hook'u fatal error gördüğünde `exit(1)` yapıp sıradaki hook'ları
 * öldürüyordu. Yani gözlemlenebilirlik, iki bootstrap satırının sırasına ve
 * onu koruyan bir yoruma bağlıydı.
 *
 * Şimdi TEK shutdown fonksiyonu var ve sıra ShutdownPriority'de veri olarak
 * duruyor. Provider'ların `boot()` sırası artık DOĞRULUĞU ETKİLEMİYOR:
 * MONITOR_FLUSH (100) her hâlükârda FATAL_RESPONSE (50)'den önce çalışır.
 */
final class ErrorHandlingProvider extends AbstractServiceProvider implements BootableProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->singleton(JsonRenderer::class);

        $builder->factory(
            ExceptionHandler::class,
            [self::class, 'handler'],
            Lifetime::SINGLETON,
            dependsOn: [
                // Scoped LoggerInterface DEĞİL: ExceptionHandler singleton.
                LoggerLocator::class,
                AppConfig::class,
                JsonRenderer::class,
                RecorderLocator::class,
                ScrubberInterface::class,
            ],
        );
    }

    public function provides(): array
    {
        return [JsonRenderer::class, ExceptionHandler::class];
    }

    public function boot(ContainerInterface $container): void
    {
        // Registry geçilir: fatal-error hook'u doğrudan
        // register_shutdown_function ile değil, öncelikli sırayla kaydedilir
        // (bkz. ExceptionHandler::register() docblock'u ve ShutdownPriority).
        $container->get(ExceptionHandler::class)->register(
            $container->get(ShutdownRegistry::class)
        );
    }

    // ── Factory ────────────────────────────────────────────────────

    public static function handler(PsrContainerInterface $container): ExceptionHandler
    {
        return new ExceptionHandler(
            // LOCATOR: her log çağrısında aktif scope'un logger'ını çözer,
            // dolayısıyla hata satırları DOĞRU isteğin request_id'sini taşır.
            // Scoped logger'ı doğrudan enjekte etmek 1. isteğin kimliğini
            // sonsuza pinler — hata teşhisinde en çok ihtiyaç duyulan bilgiyi
            // sessizce yanlış yapar.
            logger: $container->get(LoggerLocator::class),
            debug: !$container->get(AppConfig::class)->production,
            renderer: $container->get(JsonRenderer::class),
            // LOCATOR enjekte edilir, Recorder değil: ExceptionHandler
            // singleton, Recorder scoped. Doğrudan enjeksiyon §19'un
            // yasakladığı Singleton → Scoped kenarını üretir ve 1. isteğin
            // recorder'ını sonsuza pinlerdi.
            recorder: $container->get(RecorderLocator::class),
            scrubber: $container->get(ScrubberInterface::class),
        );
    }
}
