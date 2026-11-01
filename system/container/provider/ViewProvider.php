<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Engine\TwigFactory;
use System\Engine\ViewPathRegistry;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use System\Runtime\Sapi;

final class ViewProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(
            FilesystemLoader::class,
            [self::class, 'loader'],
            Lifetime::SINGLETON,
            dependsOn: [AppConfig::class],
        );

        $builder->singleton(ViewPathRegistry::class);

        $builder->singleton(TwigFactory::class);

        $builder->factory(
            Environment::class,
            [self::class, 'twig'],
            Lifetime::SINGLETON,
            dependsOn: [TwigFactory::class],
        );
    }

    public function provides(): array
    {
        return [
            FilesystemLoader::class,
            ViewPathRegistry::class,
            TwigFactory::class,
            Environment::class,
        ];
    }

    public function supports(Sapi $sapi): bool
    {
        return true;
    }

    public static function loader(PsrContainerInterface $container): FilesystemLoader
    {
        return new FilesystemLoader([], $container->get(AppConfig::class)->root);
    }

    public static function twig(PsrContainerInterface $container): Environment
    {
        return $container->get(TwigFactory::class)->create();
    }
}
