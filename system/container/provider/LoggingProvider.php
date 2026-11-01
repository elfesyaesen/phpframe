<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Config\LogConfig;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Lifetime\Lifetime;
use System\Logging\Contracts\LoggerInterface;
use System\Logging\Handlers\FileHandler;
use System\Logging\LogLevel;
use System\Logging\Logger;
use System\Logging\LoggerLocator;
use System\Runtime\RequestId;

final class LoggingProvider extends AbstractServiceProvider implements BootableProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(
            Logger::class,
            [self::class, 'baseLogger'],
            Lifetime::SINGLETON,
            dependsOn: [LogConfig::class, AppConfig::class],
        );

        $builder->factory(
            LoggerInterface::class,
            [self::class, 'requestLogger'],
            Lifetime::SCOPED,
            dependsOn: [Logger::class, RequestId::class],
        );

        $builder->factory(
            LoggerLocator::class,
            [self::class, 'loggerLocator'],
            Lifetime::SINGLETON,
            dependsOn: [Logger::class],
        );
    }

    public function provides(): array
    {
        return [Logger::class, LoggerInterface::class, LoggerLocator::class];
    }

    public function boot(ContainerInterface $container): void
    {
    }

    public static function baseLogger(PsrContainerInterface $container): Logger
    {
        $config = $container->get(LogConfig::class);
        $app = $container->get(AppConfig::class);

        $logger = new Logger();

        $logger->addHandler(new FileHandler(
            $config->applicationLog(),
            $app->production ? LogLevel::INFO : LogLevel::DEBUG,
            null,
            $config->maxFileSizeMb,
            $config->retention,
            $config->rotationPeriod,
        ));

        $logger->addHandler(new FileHandler(
            $config->errorLog(),
            LogLevel::ERROR,
            null,
            $config->maxFileSizeMb,
            $config->retention,
            $config->rotationPeriod,
        ));

        return $logger;
    }

    public static function requestLogger(PsrContainerInterface $container): LoggerInterface
    {
        $base = $container->get(Logger::class);
        $requestId = $container->get(RequestId::class);

        return (clone $base)->withContext(['request_id' => $requestId->value]);
    }

    public static function loggerLocator(PsrContainerInterface $container): LoggerLocator
    {
        return new LoggerLocator(
            static fn(): LoggerInterface => $container->get(LoggerInterface::class),
            $container->get(Logger::class),
        );
    }
}
