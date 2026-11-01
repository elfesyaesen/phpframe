<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Engine\ControllerServices;
use System\Routing\Router;
use System\Routing\RouterFactory;
use System\Runtime\Sapi;

final class RoutingProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->singleton(RouterFactory::class);

        $builder->factory(
            Router::class,
            [self::class, 'router'],
            Lifetime::SINGLETON,
            dependsOn: [RouterFactory::class],
        );

        $builder->scoped(ControllerServices::class);
    }

    public function provides(): array
    {
        return [RouterFactory::class, Router::class, ControllerServices::class];
    }

    public function supports(Sapi $sapi): bool
    {
        return true;
    }

    public static function router(PsrContainerInterface $container): Router
    {
        return $container->get(RouterFactory::class)->create();
    }
}
