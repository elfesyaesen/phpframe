<?php

declare(strict_types=1);

namespace System\Media\Image;

use GdImage;
use System\Media\MediaException;

final class GdImageDriver implements ImageDriverInterface
{
    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    public function name(): string
    {
        return 'gd';
    }

    public function supports(string $format): bool
    {
        return match ($format) {
            'webp'  => function_exists('imagewebp'),
            'avif'  => function_exists('imageavif'),
            default => false,
        };
    }

    public function process(string $path, string $mime, array $targets, array $formats, int $quality): array
    {
        $source = $this->load($path, $mime);
        $source = $this->orient($source, $path, $mime);

        $width  = imagesx($source);
        $height = imagesy($source);

        $variants = [];

        foreach ($targets as $name => $edge) {
            [$w, $h] = ImageProcessor::fit($width, $height, $edge);

            $canvas = ($w === $width && $h === $height) ? $source : $this->resize($source, $w, $h);

            $files = [];
            foreach ($formats as $format) {
                $files[$format] = $this->encode($canvas, $format, $quality);
            }

            unset($canvas);

            $variants[$name] = ['w' => $w, 'h' => $h, 'files' => $files];
        }

        return ['w' => $width, 'h' => $height, 'variants' => $variants];
    }

    private function load(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            'image/gif'  => @imagecreatefromgif($path),
            default      => false,
        };

        if (!$image instanceof GdImage) {
            throw new MediaException(MediaException::INVALID_FILE, "GD resmi çözemedi ({$mime}).");
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    private function orient(GdImage $image, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }

        $exif        = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3       => 180,
            5, 8    => 90,
            6, 7    => -90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if (!$rotated instanceof GdImage) {
            return $image;
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function resize(GdImage $source, int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if (!$canvas instanceof GdImage) {
            throw new MediaException(MediaException::STORAGE_FAILED, 'GD tuval oluşturamadı.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }

    private function encode(GdImage $image, string $format, int $quality): string
    {
        ob_start();

        try {
            $ok = match ($format) {
                'webp'  => imagewebp($image, null, $quality),
                'avif'  => function_exists('imageavif') && imageavif($image, null, $quality, 6),
                default => false,
            };
        } finally {
            $bytes = (string) ob_get_clean();
        }

        if (!$ok || $bytes === '') {
            throw new MediaException(MediaException::STORAGE_FAILED, "GD {$format} kodlayamadı.");
        }

        return $bytes;
    }
}
