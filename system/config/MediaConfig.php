<?php

declare(strict_types=1);

namespace System\Config;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class MediaConfig
{
    public const SENDFILE_NONE   = 'none';
    public const SENDFILE_APACHE = 'apache';
    public const SENDFILE_NGINX  = 'nginx';

    /**
     * @param array<string, MediaCollection> $collections
     */
    public function __construct(
        #[SensitiveParameter] public string $signingKey,
        #[SensitiveParameter] public string $previousSigningKey,
        public string $publicBaseUrl,
        public string $privateBaseUrl,
        public int $signedTtl,
        public string $sendfile,
        public string $nginxInternalPrefix,
        public string $imageDriver,
        public int $maxPixels,
        public int $maxDimension,
        public int $quality,
        public bool $avif,
        public int $userQuotaMb,
        public string $crossOriginResourcePolicy,
        public string $storagePath,
        public array $collections,
    ) {}

    public static function fromEnv(EnvReader $env, AppConfig $app): self
    {
        $sendfile = strtolower($env->string('MEDIA_SENDFILE', self::SENDFILE_NONE));
        if (!in_array($sendfile, [self::SENDFILE_NONE, self::SENDFILE_APACHE, self::SENDFILE_NGINX], true)) {
            throw new InvalidArgumentException("MEDIA_SENDFILE geçersiz: '{$sendfile}' (none | apache | nginx)");
        }

        $driver = strtolower($env->string('MEDIA_IMAGE_DRIVER', 'auto'));
        if (!in_array($driver, ['auto', 'gd', 'imagick'], true)) {
            throw new InvalidArgumentException("MEDIA_IMAGE_DRIVER geçersiz: '{$driver}' (auto | gd | imagick)");
        }

        $corp = strtolower($env->string('MEDIA_CORP', 'cross-origin'));
        if (!in_array($corp, ['cross-origin', 'same-site', 'same-origin'], true)) {
            throw new InvalidArgumentException("MEDIA_CORP geçersiz: '{$corp}' (cross-origin | same-site | same-origin)");
        }

        $publicBase = $env->string('MEDIA_PUBLIC_BASE_URL');

        return new self(
            signingKey: $env->string('MEDIA_SIGNING_KEY'),
            previousSigningKey: $env->string('MEDIA_SIGNING_KEY_PREVIOUS'),
            publicBaseUrl: rtrim($publicBase !== '' ? $publicBase : $app->url('media'), '/'),

            privateBaseUrl: rtrim($app->url('media'), '/'),
            signedTtl: max(60, $env->int('MEDIA_SIGNED_TTL', 600)),
            sendfile: $sendfile,
            nginxInternalPrefix: '/' . trim($env->string('MEDIA_NGINX_INTERNAL_PREFIX', '/_protected_media'), '/'),
            imageDriver: $driver,
            maxPixels: max(1, $env->int('MEDIA_MAX_PIXELS', 40_000_000)),
            maxDimension: max(64, $env->int('MEDIA_MAX_DIMENSION', 2560)),
            quality: min(100, max(40, $env->int('MEDIA_QUALITY', 82))),
            avif: $env->bool('MEDIA_AVIF', false),
            userQuotaMb: max(0, $env->int('MEDIA_USER_QUOTA_MB', 500)),
            crossOriginResourcePolicy: $corp,
            storagePath: $app->path('storage', 'media'),
            collections: self::loadCollections($app->path('config', 'media.php')),
        );
    }

    public function hasStrongSigningKey(): bool
    {
        return strlen($this->signingKey) >= 32;
    }

    public function collection(string $name): ?MediaCollection
    {
        return $this->collections[$name] ?? null;
    }

    /**
     * @return array<string, MediaCollection>
     */
    private static function loadCollections(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $definitions = require $file;

        if (!is_array($definitions)) {
            throw new InvalidArgumentException('config/media.php bir dizi döndürmeli.');
        }

        $collections = [];

        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                throw new InvalidArgumentException('config/media.php: koleksiyon adı string, tanımı dizi olmalı.');
            }

            $collections[$name] = MediaCollection::fromArray($name, $definition);
        }

        return $collections;
    }
}
