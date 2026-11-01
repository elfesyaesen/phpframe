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

                Logger::class,
                RecorderLocator::class,
            ],
        );
    }

    public function provides(): array
    {
        return [CacheInterface::class];
    }

    public static function cache(PsrContainerInterface $container): CacheInterface
    {
        $cache = (new CacheFactory(
            config: $container->get(CacheConfig::class),

            logger: $container->get(Logger::class),
        ))->create();

        $monitor = $container->get(MonitorConfig::class);

        if (!$monitor->recordsCache()) {
            return $cache;
        }

        return new RecordingCache(
            $cache,
            $container->get(RecorderLocator::class),
        );
    }
}
