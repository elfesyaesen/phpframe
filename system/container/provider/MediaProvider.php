<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\MediaConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Media\Contracts\UploadScannerInterface;
use System\Media\Image\ImageProcessor;
use System\Media\MediaDisk;
use System\Media\MediaRepository;
use System\Media\MediaResponder;
use System\Media\MediaService;
use System\Media\MediaUrlGenerator;
use System\Media\NullScanner;
use System\Media\UrlSigner;
use System\Storage\LocalFileStorage;

final class MediaProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(UrlSigner::class, [self::class, 'signer'], Lifetime::SINGLETON, dependsOn: [MediaConfig::class]);
        $builder->factory(MediaDisk::class, [self::class, 'disk'], Lifetime::SINGLETON, dependsOn: [MediaConfig::class]);
        $builder->singleton(MediaUrlGenerator::class);
        $builder->singleton(ImageProcessor::class);
        $builder->singleton(MediaResponder::class);

        $builder->singleton(UploadScannerInterface::class, NullScanner::class);

        $builder->scoped(MediaRepository::class);
        $builder->scoped(MediaService::class);
    }

    public function provides(): array
    {
        return [
            UrlSigner::class,
            MediaDisk::class,
            MediaUrlGenerator::class,
            ImageProcessor::class,
            MediaResponder::class,
            UploadScannerInterface::class,
            MediaRepository::class,
            MediaService::class,
        ];
    }

    public static function signer(PsrContainerInterface $container): UrlSigner
    {
        $config = $container->get(MediaConfig::class);

        return new UrlSigner($config->signingKey, $config->previousSigningKey, $config->signedTtl);
    }

    public static function disk(PsrContainerInterface $container): MediaDisk
    {
        return new MediaDisk(new LocalFileStorage($container->get(MediaConfig::class)->storagePath));
    }
}
