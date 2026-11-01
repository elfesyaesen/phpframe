<?php

declare(strict_types=1);

namespace System\Media\Image;

use System\Config\MediaConfig;
use System\Media\Media;
use System\Media\MediaException;

final class ImageProcessor
{
    private const MAX_SIDE = 20000;

    private ?ImageDriverInterface $driver = null;
    private bool $resolved = false;

    public function __construct(
        private readonly MediaConfig $config,
    ) {}

    public function driver(): ?ImageDriverInterface
    {
        if ($this->resolved) {
            return $this->driver;
        }

        $this->resolved = true;

        $preferImagick = in_array($this->config->imageDriver, ['auto', 'imagick'], true);

        if ($preferImagick && ImagickImageDriver::available()) {
            return $this->driver = new ImagickImageDriver($this->config->maxPixels);
        }

        if ($this->config->imageDriver !== 'imagick' && GdImageDriver::available()) {
            return $this->driver = new GdImageDriver();
        }

        return null;
    }

    /**
     * @param array<string, int> $variants
     * @return array{w: int, h: int, variants: array<string, array{w: int, h: int, files: array<string, string>}>}
     * @throws MediaException
     */
    public function process(string $path, string $mime, array $variants): array
    {
        $driver = $this->driver();

        if ($driver === null) {
            throw new MediaException(
                MediaException::DRIVER_UNAVAILABLE,
                'Resim sürücüsü yok (GD WebP desteğiyle veya Imagick gerekli).',
            );
        }

        $this->assertDimensions($path);

        $formats = ['webp'];
        if ($this->config->avif && $driver->supports('avif')) {
            $formats[] = 'avif';
        }

        $targets = [Media::FULL_VARIANT => $this->config->maxDimension] + $variants;

        return $driver->process($path, $mime, $targets, $formats, $this->config->quality);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function fit(int $width, int $height, int $edge): array
    {
        $longest = max($width, $height);

        if ($longest <= $edge) {
            return [$width, $height];
        }

        $ratio = $edge / $longest;

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    private function assertDimensions(string $path): void
    {
        $info = @getimagesize($path);

        if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new MediaException(MediaException::INVALID_FILE, 'Resim boyutları okunamadı.');
        }

        [$width, $height] = $info;

        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > $this->config->maxPixels) {
            throw new MediaException(
                MediaException::TOO_MANY_PIXELS,
                "Resim çok büyük ({$width}×{$height}).",
            );
        }
    }
}
