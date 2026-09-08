<?php

declare(strict_types=1);

namespace System\Engine;

use System\Exceptions\BadRequestException;
use finfo;

/**
 * Secure File Manager
 *
 * Güvenli dosya yükleme, path traversal koruması, MIME doğrulama.
 */
class FileManager
{
    /**
     * @var array<string>
     */
    protected array $errors = [];

    /**
     * İzin verilen klasörler (whitelist)
     *
     * @var array<string>
     */
    private array $allowedFolders = [
        'products',
        'users',
        'uploads',
        'temp',
        'categories',
        'brands',
        'banners',
        'content',
    ];

    /**
     * İzin verilen MIME tipleri
     *
     * @var array<string>
     */
    private array $allowedMimeTypes = ['image/jpeg','image/png','image/gif','image/webp'];

    /**
     * Tehlikeli dosya uzantıları
     *
     * @var array<string>
     */
    private array $dangerousExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phar',
        'exe', 'sh', 'bat', 'cmd', 'com', 'scr', 'msi',
        'js', 'vbs', 'wsf', 'wsh',
        'htaccess', 'htpasswd', 
    ];

    /**
     * Maksimum dosya boyutu (5MB default)
     */
    private int $maxFileSize = 5242880;

    /**
     * Güvenli dosya yükleme
     *
     * @param string $folder Hedef klasör
     * @param array $file Dosya bilgileri
     * @param bool $convert WebP'ye dönüştür
     * @param bool $keepOriginalName Orijinal dosya adını koru
     * @throws BadRequestException
     */
    public function upload(string $folder, array $file, bool $convert = false, bool $keepOriginalName = false): string|false
    {
        // 1. Path traversal koruması
        $folder = $this->validateFolder($folder);

        // 2. Dosya temel kontrolleri
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->errors[] = 'Geçersiz dosya yükleme';
            return false;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = $this->uploadError($file['error']);
            return false;
        }

        // 3. Dosya boyutu kontrolü
        if (!$this->validateFileSize($file['size'])) {
            $this->errors[] = 'Dosya boyutu çok büyük (max: ' . $this->formatBytes($this->maxFileSize) . ')';
            return false;
        }

        // 4. Uzantı kontrolü
        $originalName = $file['name'] ?? 'unknown';
        if (!$this->validateExtension($originalName)) {
            $this->errors[] = 'Bu dosya uzantısına izin verilmiyor';
            return false;
        }

        // 5. MIME tipi kontrolü (dosya içeriğinden)
        if (!$this->validateMimeType($file['tmp_name'])) {
            $this->errors[] = 'Bu dosya tipine izin verilmiyor';
            return false;
        }

        // 6. Dizin oluştur (güvenli)
        $directory = APP_ROOT . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'image' . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
        $this->createDirectory($directory);

        // 7. Güvenli dosya adı oluştur
        $fileName = $this->generateSecureFileName($originalName, $keepOriginalName);
        $destination = $directory . $fileName;

        // 8. Dosyayı taşı
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $this->errors[] = 'Dosya yüklenemedi';
            return false;
        }

        // 9. Yükleme sonrası MIME kontrolü (double check)
        if (!$this->validateMimeType($destination)) {
            @unlink($destination);
            $this->errors[] = 'Dosya içeriği geçersiz';
            return false;
        }

        // 10. WebP dönüşümü (isteğe bağlı)
        if ($convert && $this->isImage($destination)) {
            $webpFileName = $this->convertToWebp($destination);
            if ($webpFileName) {
                @unlink($destination);
                return $folder . '/' . $webpFileName;
            }
        }

        return $folder . '/' . $fileName;
    }

    /**
     * Klasör adını doğrula ve temizle (path traversal koruması)
     *
     * @throws BadRequestException
     */
    private function validateFolder(string $folder): string
    {
        // Path traversal karakterlerini temizle
        $folder = str_replace(['..', '/', '\\', "\0"], '', $folder);
        $folder = trim($folder);

        if (empty($folder)) {
            $folder = 'uploads';
        }

        // Whitelist kontrolü
        if (!in_array($folder, $this->allowedFolders, true)) {
            throw new BadRequestException(
                'Geçersiz yükleme klasörü',
                ['allowed' => $this->allowedFolders]
            );
        }

        return $folder;
    }

    /**
     * Dosya boyutunu doğrula
     */
    private function validateFileSize(int $size): bool
    {
        return $size > 0 && $size <= $this->maxFileSize;
    }

    /**
     * Dosya uzantısını doğrula
     */
    private function validateExtension(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Tehlikeli uzantı kontrolü
        if (in_array($extension, $this->dangerousExtensions, true)) {
            return false;
        }

        // Çift uzantı kontrolü (örn: file.php.jpg)
        $parts = explode('.', $filename);
        if (count($parts) > 2) {
            foreach ($parts as $part) {
                if (in_array(strtolower($part), $this->dangerousExtensions, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * MIME tipini dosya içeriğinden doğrula
     */
    private function validateMimeType(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        if ($mimeType === false) {
            return false;
        }

        return in_array($mimeType, $this->allowedMimeTypes, true);
    }

    /**
     * Dosya resim mi kontrol et (içerik bazlı)
     */
    private function isImage(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $imageType = @exif_imagetype($filePath);
        return $imageType !== false;
    }

    /**
     * Güvenli dosya adı oluştur
     */
    private function generateSecureFileName(string $originalName, bool $keepOriginalName = false): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Sadece izin verilen uzantılar
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (!in_array($extension, $allowedExtensions, true)) {
            $extension = 'jpg';
        }

        // Orijinal dosya adını koru
        if ($keepOriginalName) {
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            // Tehlikeli karakterleri temizle
            $baseName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $baseName);
            if (empty($baseName)) {
                $baseName = 'file_' . time();
            }
            return $baseName . '.' . $extension;
        }

        // Rastgele, tahmin edilemez dosya adı
        $uniqueId = bin2hex(random_bytes(8));
        $timestamp = date('YmdHis');

        return $timestamp . '-' . $uniqueId . '.' . $extension;
    }

    /**
     * Dizin oluştur (race condition korumalı)
     */
    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            // @ ile suppress ediyoruz çünkü race condition olabilir
            @mkdir($directory, 0755, true);

            // Başarılı olduğunu doğrula
            if (!is_dir($directory)) {
                throw new BadRequestException('Dizin oluşturulamadı');
            }
        }
    }

    /**
     * WebP'ye dönüştür
     */
    private function convertToWebp(string $sourcePath): ?string
    {
        if (!function_exists('imagewebp')) {
            return null;
        }

        $imageType = @exif_imagetype($sourcePath);
        if ($imageType === false) {
            return null;
        }

        $image = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => $this->createPngImage($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => null,
        };

        if ($image === null || $image === false) {
            return null;
        }

        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $sourcePath);

        if (@imagewebp($image, $webpPath, 80)) {
            return basename($webpPath);
        }

        return null;
    }

    /**
     * PNG görüntüsü oluştur (alpha desteği ile)
     *
     * @return \GdImage|false
     */
    private function createPngImage(string $sourcePath): \GdImage|false
    {
        $image = @imagecreatefrompng($sourcePath);
        if ($image === false) {
            return false;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Byte'ı okunabilir formata çevir
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Maksimum dosya boyutunu ayarla
     */
    public function setMaxFileSize(int $bytes): self
    {
        $this->maxFileSize = $bytes;
        return $this;
    }

    /**
     * İzin verilen klasörleri ayarla
     *
     * @param array<string> $folders
     */
    public function setAllowedFolders(array $folders): self
    {
        $this->allowedFolders = $folders;
        return $this;
    }

    /**
     * İzin verilen klasör ekle
     */
    public function addAllowedFolder(string $folder): self
    {
        if (!in_array($folder, $this->allowedFolders, true)) {
            $this->allowedFolders[] = $folder;
        }
        return $this;
    }

    /**
     * İzin verilen MIME tiplerini ayarla
     *
     * @param array<string> $mimeTypes
     */
    public function setAllowedMimeTypes(array $mimeTypes): self
    {
        $this->allowedMimeTypes = $mimeTypes;
        return $this;
    }

    /**
     * Hataları döndür
     *
     * @return array<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Yükleme hata mesajı
     */
    private function uploadError(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'Dosya boyutu sunucu limitini aşıyor',
            UPLOAD_ERR_FORM_SIZE => 'Dosya boyutu form limitini aşıyor',
            UPLOAD_ERR_PARTIAL => 'Dosya kısmen yüklendi',
            UPLOAD_ERR_NO_FILE => 'Dosya yüklenmedi',
            UPLOAD_ERR_NO_TMP_DIR => 'Geçici klasör bulunamadı',
            UPLOAD_ERR_CANT_WRITE => 'Dosya yazılamadı',
            UPLOAD_ERR_EXTENSION => 'Yükleme bir eklenti tarafından durduruldu',
            default => 'Bilinmeyen yükleme hatası',
        };
    }

    /**
     * Dosya sil (güvenli)
     */
    public function delete(string $filePath): bool
    {
        // Path traversal koruması
        $realPath = realpath(APP_ROOT . '/public/image/' . $filePath);

        if ($realPath === false) {
            return false;
        }

        // Dosyanın image dizini İÇİNDE olduğunu doğrula. Ayraç eklenmezse
        // `/public/image-secret/x` gibi KARDEŞ dizinler de prefix'i geçerdi
        // (str_starts_with('/public/image-secret', '/public/image') === true).
        $imageDir = realpath(APP_ROOT . '/public/image');
        if ($imageDir === false || !str_starts_with($realPath, $imageDir . DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (file_exists($realPath) && is_file($realPath)) {
            return @unlink($realPath);
        }

        return false;
    }
}
