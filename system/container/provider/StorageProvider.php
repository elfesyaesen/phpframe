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

final class StorageProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
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

    public static function storage(PsrContainerInterface $container): FileStorageInterface
    {
        return new LocalFileStorage(
            $container->get(AppConfig::class)->path('storage', 'uploads')
        );
    }
}
