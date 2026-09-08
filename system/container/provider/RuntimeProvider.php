<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Config\MonitorConfig;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Lifetime\Lifetime;
use System\Runtime\RequestId;
use System\Runtime\RequestSnapshot;
use System\Runtime\Sapi;

/**
 * İstek/çalıştırma bağlamı: RequestId, RequestSnapshot, zaman dilimi.
 */
final class RuntimeProvider extends AbstractServiceProvider implements BootableProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // SCOPED: her istek kendi korelasyon kimliğine sahip. Singleton
        // olsaydı bir worker'ın gördüğü tüm istekler aynı kimliği paylaşır ve
        // log ↔ monitor eşleştirmesi sessizce yalan söylerdi.
        $builder->factory(
            RequestId::class,
            [self::class, 'requestId'],
            Lifetime::SCOPED,
        );

        // SCOPED ve pratikte Kernel tarafından EAGER olarak scope'a
        // yerleştirilir (php://input tek kez okunabilir). Bu tanım grafiğin
        // kapalı olması ve doğrulanabilmesi için var; factory yalnızca
        // Kernel önceden yerleştirmediyse çalışır.
        $builder->factory(
            RequestSnapshot::class,
            [self::class, 'snapshot'],
            Lifetime::SCOPED,
            dependsOn: [MonitorConfig::class],
        );
    }

    public function provides(): array
    {
        return [RequestId::class, RequestSnapshot::class];
    }

    public function boot(ContainerInterface $container): void
    {
        // Zaman dilimi eskiden config.php'de `date_default_timezone_set(APP_TIMEZONE)`
        // olarak ayarlanıyordu. Bu bir SÜREÇ GENELİ YAN ETKİ, dolayısıyla
        // tanım değil boot işi.
        date_default_timezone_set($container->get(AppConfig::class)->timezone);
    }

    // ── Factory'ler ────────────────────────────────────────────────

    public static function requestId(PsrContainerInterface $container): RequestId
    {
        return PHP_SAPI === 'cli'
            ? RequestId::forCli()
            : RequestId::fromServer($_SERVER);
    }

    public static function snapshot(PsrContainerInterface $container): RequestSnapshot
    {
        if (PHP_SAPI === 'cli') {
            return RequestSnapshot::forCli();
        }

        $monitor = $container->get(MonitorConfig::class);

        return RequestSnapshot::capture(
            server: $_SERVER,
            bufferingResponse: $monitor->enabled && $monitor->captureResponseBody,
        );
    }
}
