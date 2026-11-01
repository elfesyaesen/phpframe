<?php

declare(strict_types=1);

namespace System\Media\Image;

use Imagick;
use ImagickException;
use System\Media\MediaException;

final class ImagickImageDriver implements ImageDriverInterface
{
    private const DECODERS = [
        'image/jpeg' => 'jpeg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/gif'  => 'gif',
    ];

    public function __construct(
        private readonly int $maxPixels,
    ) {}

    public static function available(): bool
    {
        return extension_loaded('imagick') && Imagick::queryFormats('WEBP') !== [];
    }

    public function name(): string
    {
        return 'imagick';
    }

    public function supports(string $format): bool
    {
        return extension_loaded('imagick') && Imagick::queryFormats(strtoupper($format)) !== [];
    }

    public function process(string $path, string $mime, array $targets, array $formats, int $quality): array
    {
        $decoder = self::DECODERS[$mime] ?? null;

        if ($decoder === null) {
            throw new MediaException(MediaException::INVALID_FILE, "Imagick için çözücü yok ({$mime}).");
        }

        try {
            $image = new Imagick();

            $image->setResourceLimit(Imagick::RESOURCETYPE_AREA, $this->maxPixels);
            $image->readImage($decoder . ':' . $path);

            if ($image->getNumberImages() > 1) {
                $image->setIteratorIndex(0);
                $first = $image->getImage();
                $image->clear();
                $image = $first;
            }

            $this->orient($image);
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->stripImage();

            $width  = $image->getImageWidth();
            $height = $image->getImageHeight();

            $variants = [];

            foreach ($targets as $name => $edge) {
                [$w, $h] = ImageProcessor::fit($width, $height, $edge);

                $canvas = clone $image;
                if ($w !== $width || $h !== $height) {
                    $canvas->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1);
                }

                $files = [];
                foreach ($formats as $format) {
                    $encoded = clone $canvas;
                    $encoded->setImageFormat($format);
                    $encoded->setImageCompressionQuality($quality);
                    $encoded->stripImage();
                    $files[$format] = $encoded->getImageBlob();
                    $encoded->clear();
                }

                $canvas->clear();
                $variants[$name] = ['w' => $w, 'h' => $h, 'files' => $files];
            }

            $image->clear();
        } catch (ImagickException $e) {
            throw new MediaException(MediaException::INVALID_FILE, 'Imagick resmi işleyemedi.', $e);
        }

        return ['w' => $width, 'h' => $height, 'variants' => $variants];
    }

    private function orient(Imagick $image): void
    {
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();

            return;
        }

        match ($image->getImageOrientation()) {
            Imagick::ORIENTATION_TOPRIGHT    => $image->flopImage(),
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage('#000', 180),
            Imagick::ORIENTATION_BOTTOMLEFT  => $image->flipImage(),
            Imagick::ORIENTATION_LEFTTOP     => $image->transposeImage(),
            Imagick::ORIENTATION_RIGHTTOP    => $image->rotateImage('#000', 90),
            Imagick::ORIENTATION_RIGHTBOTTOM => $image->transverseImage(),
            Imagick::ORIENTATION_LEFTBOTTOM  => $image->rotateImage('#000', -90),
            default                          => null,
        };

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }
}
