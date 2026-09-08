<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Cache\CacheFactory;
use System\Cache\CacheInterface;
use System\Config\CacheConfig;
use System\Config\MonitorConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Logging\Logger;
use System\Monitor\Instrumentation\RecordingCache;
use System\Monitor\RecorderLocator;

/**
 * Cache-first katmanı: Redis primary, erişilemezse zarif degrade.
 */
final class CacheProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(
            CacheInterface::class,
            [self::class, 'cache'],
            Lifetime::SINGLETON,
            dependsOn: [
                CacheConfig::class,
                MonitorConfig::class,
                // Scoped LoggerInterface DEĞİL, singleton taban Logger:
                // aşağıdaki gerekçeye bak.
                Logger::class,
                RecorderLocator::class,
            ],
        );
    }

    public function provides(): array
    {
        return [CacheInterface::class];
    }

    /**
     * Cache sürücüsü, monitor açıkken hit/miss sayan dekoratörle sarılı.
     *
     * Sürücü seçimi CacheFactory'de kalır; dekoratör hangisi gelirse onu sarar
     * ve iç davranışa dokunmaz.
     */
    public static function cache(PsrContainerInterface $container): CacheInterface
    {
        $cache = (new CacheFactory(
            config: $container->get(CacheConfig::class),
            // SINGLETON taban Logger kullanılır, scoped LoggerInterface DEĞİL.
            //
            // Cache singleton'dır; içine scoped bir logger yakalamak 1. isteğin
            // logger'ını (ve onun request_id context'ini) sonsuza pinlerdi.
            // Taban Logger request_id taşımaz ama singleton'dır, dolayısıyla
            // güvenle yakalanabilir — ve buradaki tek log satırı boot-zamanı
            // bir degrade uyarısı olduğu için request korelasyonu gerekmez.
            logger: $container->get(Logger::class),
        ))->create();

        $monitor = $container->get(MonitorConfig::class);

        if (!$monitor->recordsCache()) {
            return $cache;
        }

        // Dekoratör singleton bir cache'i sarar ama RECORDER scoped.
        // Bu yüzden Recorder DOĞRUDAN değil LOCATOR üzerinden verilir:
        // her cache erişimi aktif scope'un recorder'ına yazılır, yoksa
        // sessizce yok sayılır. Doğrudan enjeksiyon 1. isteğin recorder'ını
        // sonsuza pinler ve tüm cache metrikleri o isteğe yazılırdı.
        return new RecordingCache(
            $cache,
            $container->get(RecorderLocator::class),
        );
    }
}
