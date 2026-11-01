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
use System\Config\HttpConfig;
use System\Http\TrustedProxies;
use System\Runtime\RequestId;
use System\Runtime\RequestSnapshot;

final class RuntimeProvider extends AbstractServiceProvider implements BootableProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(
            RequestId::class,
            [self::class, 'requestId'],
            Lifetime::SCOPED,
            dependsOn: [HttpConfig::class],
        );

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
        date_default_timezone_set($container->get(AppConfig::class)->timezone);
    }

    public static function requestId(PsrContainerInterface $container): RequestId
    {
        return PHP_SAPI === 'cli'
            ? RequestId::forCli()
            : RequestId::fromServer(
                $_SERVER,
                new TrustedProxies($container->get(HttpConfig::class)->trustedProxies),
            );
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
