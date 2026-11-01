<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Console\Application;
use System\Console\Loader\CommandLoader;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Runtime\Sapi;

final class ConsoleProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(
            CommandLoader::class,
            [self::class, 'loader'],
            Lifetime::SINGLETON,
            dependsOn: [AppConfig::class],
        );

        $builder->factory(
            Application::class,
            [self::class, 'application'],
            Lifetime::SINGLETON,
            dependsOn: [CommandLoader::class],
        );
    }

    public function provides(): array
    {
        return [CommandLoader::class, Application::class];
    }

    public function supports(Sapi $sapi): bool
    {
        return $sapi->isCli();
    }

    public static function loader(PsrContainerInterface $container): CommandLoader
    {
        return new CommandLoader(
            commandsPath: $container->get(AppConfig::class)->path('system', 'console', 'commands'),
            namespace: 'System\\Console\\Commands',
            container: $container,
        );
    }

    public static function application(PsrContainerInterface $container): Application
    {
        $application = new Application();
        $application->setCommandLoader($container->get(CommandLoader::class));

        $application->setAutoExit(false);

        return $application;
    }
}
