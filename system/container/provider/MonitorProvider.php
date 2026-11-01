<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\MonitorConfig;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Lifetime\Lifetime;
use System\Database\Database;
use System\Http\Request;
use System\Logging\Contracts\LoggerInterface;
use System\Logging\LoggerLocator;
use System\Monitor\CollectorSet;
use System\Monitor\Collectors\FatalErrorCollector;
use System\Monitor\Collectors\HttpCollector;
use System\Monitor\Contracts\RecorderInterface;
use System\Monitor\Contracts\SamplerInterface;
use System\Monitor\Contracts\ScrubberInterface;
use System\Monitor\Contracts\StorageInterface;
use System\Monitor\Recorder;
use System\Monitor\RecorderLocator;
use System\Monitor\RequestContext;
use System\Monitor\Sampler\PathSampler;
use System\Monitor\Scrubber\KeyScrubber;
use System\Monitor\Storage\DatabaseStorage;
use System\Monitor\Storage\NullStorage;
use System\Runtime\RequestId;
use System\Runtime\RequestSnapshot;
use System\Runtime\Sapi;
use System\Runtime\ShutdownPriority;
use System\Runtime\ShutdownRegistry;

final class MonitorProvider extends AbstractServiceProvider implements BootableProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->singleton(ScrubberInterface::class, KeyScrubber::class);

        $builder->factory(
            SamplerInterface::class,
            [self::class, 'sampler'],
            Lifetime::SINGLETON,
            dependsOn: [MonitorConfig::class],
        );

        $builder->factory(
            StorageInterface::class,
            [self::class, 'storage'],
            Lifetime::SINGLETON,
            dependsOn: [MonitorConfig::class, LoggerLocator::class],
        );

        $builder->factory(
            RecorderInterface::class,
            [self::class, 'recorder'],
            Lifetime::SCOPED,
            dependsOn: [
                StorageInterface::class,
                SamplerInterface::class,
                ScrubberInterface::class,
                LoggerInterface::class,
                MonitorConfig::class,
                RequestId::class,

                CollectorSet::class,
            ],
        );

        $builder->factory(
            RecorderLocator::class,
            [self::class, 'recorderLocator'],
            Lifetime::SINGLETON,
        );

        $builder->factory(
            RequestContext::class,
            [self::class, 'requestContext'],
            Lifetime::SCOPED,
            dependsOn: [RequestSnapshot::class],
        );

        $builder->factory(
            HttpCollector::class,
            [self::class, 'httpCollector'],
            Lifetime::SCOPED,
            dependsOn: [
                RequestContext::class,
                Request::class,
                ScrubberInterface::class,
                MonitorConfig::class,
            ],
        );

        $builder->scoped(FatalErrorCollector::class);

        $builder->tag([
            HttpCollector::class,
            FatalErrorCollector::class,
        ], CollectorSet::TAG);

        $builder->scoped(CollectorSet::class);
    }

    public function provides(): array
    {
        return [
            ScrubberInterface::class,
            SamplerInterface::class,
            StorageInterface::class,
            RecorderInterface::class,
            RecorderLocator::class,
            RequestContext::class,
            HttpCollector::class,
            FatalErrorCollector::class,
            CollectorSet::class,
        ];
    }

    public function boot(ContainerInterface $container): void
    {
        $config = $container->get(MonitorConfig::class);

        if (!$config->enabled || PHP_SAPI === 'cli') {
            return;
        }

        if ($config->captureResponseBody) {
            ob_start();
        }

        $container->get(ShutdownRegistry::class)->on(
            ShutdownPriority::MONITOR_FLUSH,
            static function () use ($container): void {
                $scope = $container->scope();

                if ($scope === null) {
                    return;
                }

                $scope->get(RecorderInterface::class)->flush();
            },
        );
    }

    public static function sampler(PsrContainerInterface $container): SamplerInterface
    {
        $config = $container->get(MonitorConfig::class);

        return new PathSampler(skipPrefixes: $config->skipPrefixes);
    }

    public static function storage(PsrContainerInterface $container): StorageInterface
    {
        $config = $container->get(MonitorConfig::class);

        if (!$config->enabled) {
            return new NullStorage();
        }

        return new DatabaseStorage(
            databaseProvider: static fn(): Database => $container->get(Database::class),
            logger: $container->get(LoggerLocator::class),
        );
    }

    public static function recorder(PsrContainerInterface $container): RecorderInterface
    {
        $config = $container->get(MonitorConfig::class);
        $recording = $config->enabled && PHP_SAPI !== 'cli';

        $collectors = $recording
            ? $container->get(CollectorSet::class)->all()
            : [];

        return new Recorder(
            storage: $container->get(StorageInterface::class),
            sampler: $container->get(SamplerInterface::class),
            scrubber: $container->get(ScrubberInterface::class),
            logger: $container->get(LoggerInterface::class),
            collectors: $collectors,
            recording: $recording,
            requestId: $container->get(RequestId::class)->value,
            maxQueries: $config->maxQueries,
            nPlusOneThreshold: $config->nPlusOneThreshold,
        );
    }

    public static function recorderLocator(PsrContainerInterface $container): RecorderLocator
    {
        return new RecorderLocator(
            static fn(): RecorderInterface => $container->get(RecorderInterface::class)
        );
    }

    public static function httpCollector(PsrContainerInterface $container): HttpCollector
    {
        $config = $container->get(MonitorConfig::class);

        return new HttpCollector(
            context: $container->get(RequestContext::class),
            request: $container->get(Request::class),
            scrubber: $container->get(ScrubberInterface::class),
            maxBodyBytes: $config->maxBodyBytes,
            captureRequestBody: $config->captureRequestBody,
            captureResponseBody: $config->captureResponseBody,
        );
    }

    public static function requestContext(PsrContainerInterface $container): RequestContext
    {
        $snapshot = $container->get(RequestSnapshot::class);

        return new RequestContext(
            startedAt: $snapshot->startedAt,
            rawInput: $snapshot->rawInput,
            bufferingResponse: $snapshot->bufferingResponse,
        );
    }
}
