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

/**
 * Konsol uygulaması — yalnızca CLI.
 *
 * Bu provider'ın varlığı, CLI komutlarının container'dan çözülmesini sağlar:
 * artık komutlar constructor injection kullanabilir ve
 * `new Database(new NullRecorder())` gibi elle kurulumlar gerekmez.
 */
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

    // ── Factory'ler ────────────────────────────────────────────────

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

        // `exit()` KAPATILIR: Application::run() içindeki exit, `frame`
        // script'indeki temizlik adımlarını (scope dispose) ve shutdown
        // sırasını atlar. Çıkış kodunu `frame` verir.
        $application->setAutoExit(false);

        return $application;
    }
}
