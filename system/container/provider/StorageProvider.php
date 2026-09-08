<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Storage\FileStorageInterface;
use System\Storage\LocalFileStorage;
use System\Storage\UploadValidator;

/**
 * Dosya depolama.
 */
final class StorageProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // LocalFileStorage `string $basePath` alır — primitive, autowire
        // edilemez (DI-plan §10). Yol AppConfig'ten türetildiği için factory.
        $builder->factory(
            FileStorageInterface::class,
            [self::class, 'storage'],
            Lifetime::SINGLETON,
            dependsOn: [AppConfig::class],
        );

        $builder->singleton(UploadValidator::class);
    }

    public function provides(): array
    {
        return [FileStorageInterface::class, UploadValidator::class];
    }

    // ── Factory ────────────────────────────────────────────────────

    public static function storage(PsrContainerInterface $container): FileStorageInterface
    {
        return new LocalFileStorage(
            $container->get(AppConfig::class)->path('image')
        );
    }
}
