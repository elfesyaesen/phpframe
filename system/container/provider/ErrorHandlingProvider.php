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
        $container->get(ExceptionHandler::class)->register(
            $container->get(ShutdownRegistry::class)
        );
    }

    public static function handler(PsrContainerInterface $container): ExceptionHandler
    {
        return new ExceptionHandler(
            logger: $container->get(LoggerLocator::class),
            debug: !$container->get(AppConfig::class)->production,
            renderer: $container->get(JsonRenderer::class),

            recorder: $container->get(RecorderLocator::class),
            scrubber: $container->get(ScrubberInterface::class),
        );
    }
}
