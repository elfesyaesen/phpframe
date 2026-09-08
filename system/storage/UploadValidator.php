<?php

declare(strict_types=1);

namespace System\Storage;

final class UploadValidator
{
    /**
     * Yuklenen dosyayi MIME ve boyut acisindan dogrular.
     * Donen deger: tespit edilen gercek MIME tipi.
     *
     * @param array<string,mixed> $uploadedFile $_FILES['x'] formatinda
     * @param string[]            $allowedMimes Izin verilen gercek MIME tipleri (allow-list)
     */
    public function validate(array $uploadedFile, array $allowedMimes, int $maxBytes): string
    {
        if (!isset($uploadedFile['tmp_name'], $uploadedFile['error'], $uploadedFile['size'])) {
            throw new StorageException('Gecersiz upload dizisi.');
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new StorageException('Upload hatasi (code: ' . (int) $uploadedFile['error'] . ').');
        }

        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            throw new StorageException('Gecersiz upload kaynagi.');
        }

        $size = (int) $uploadedFile['size'];
        if ($size <= 0) {
            throw new StorageException('Bos dosya.');
        }
        if ($size > $maxBytes) {
            throw new StorageException("Dosya boyutu limiti astı (max: {$maxBytes} byte).");
        }

        $mime = $this->detectMime($uploadedFile['tmp_name']);
        if ($mime === null) {
            throw new StorageException('MIME tipi tespit edilemedi.');
        }

        if (!in_array($mime, $allowedMimes, true)) {
            throw new StorageException("Izin verilmeyen dosya tipi: {$mime}");
        }

        return $mime;
    }

    public function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'        => 'jpg',
            'image/png'         => 'png',
            'image/webp'        => 'webp',
            'image/gif'         => 'gif',
            'image/heic'        => 'heic',
            'application/pdf'   => 'pdf',
            'text/plain'        => 'txt',
            'application/json'  => 'json',
            default             => 'bin',
        };
    }

    private function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }
}
