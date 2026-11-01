<?php

declare(strict_types=1);

namespace System\Media;

use System\Config\MediaConfig;

final class MediaUrlGenerator
{
    public function __construct(
        private readonly MediaConfig $config,
        private readonly UrlSigner $signer,
    ) {}

    public function url(Media $media, string $variant = Media::FULL_VARIANT, ?string $format = null, ?int $now = null): ?string
    {
        if (!$media->isImage()) {
            $variant = Media::FILE_VARIANT;
        }

        $format ??= $media->defaultFormat($variant);

        if ($format === null || $media->file($variant, $format) === null) {
            return null;
        }

        $file = $variant . '.' . $format;

        if ($media->isPublic()) {
            return $this->config->publicBaseUrl . '/p/' . $media->uuid . '/' . $file;
        }

        $expires = $this->signer->expiry($now);

        return $this->config->privateBaseUrl . '/s/' . $media->uuid . '/' . $file
            . '?exp=' . $expires . '&sig=' . $this->signer->sign($media->uuid, $file, $expires);
    }

    /**
     * @return array<string, string>
     */
    public function urls(Media $media, ?int $now = null): array
    {
        $urls = [];

        foreach (array_keys($media->variants) as $variant) {
            $url = $this->url($media, (string) $variant, null, $now);

            if ($url !== null) {
                $urls[(string) $variant] = $url;
            }
        }

        return $urls;
    }

    public function srcset(Media $media, string $format = 'webp', ?int $now = null): string
    {
        if (!$media->isImage()) {
            return '';
        }

        $candidates = [];

        foreach ($media->variants as $variant => $data) {
            $url = $this->url($media, (string) $variant, $format, $now);

            if ($url !== null && isset($data['w'])) {
                $candidates[(int) $data['w']] = $url . ' ' . (int) $data['w'] . 'w';
            }
        }

        ksort($candidates);

        return implode(', ', $candidates);
    }

    public function expiresAt(Media $media, ?int $now = null): ?int
    {
        return $media->isPublic() ? null : $this->signer->expiry($now);
    }
}
