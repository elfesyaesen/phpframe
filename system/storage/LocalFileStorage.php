<?php

declare(strict_types=1);

namespace System\Storage;

final class LocalFileStorage implements FileStorageInterface
{
    /**
     * Yazılması yasak (çalıştırılabilir / sunucu-kontrol) uzantılar.
     * Defense-in-depth: çağıran MIME doğrulamasını (UploadValidator) atlasa veya
     * kullanıcı-kontrollü bir key verse bile executable upload → RCE engellenir.
     */
    private const BLOCKED_WRITE_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phar',
        'cgi', 'pl', 'asp', 'aspx', 'jsp', 'jspx', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd',
        'htaccess', 'htpasswd',
    ];

    public function __construct(
        private readonly string $basePath,
    ) {
        if (!is_dir($this->basePath)) {
            if (!@mkdir($this->basePath, 0755, true) && !is_dir($this->basePath)) {
                throw new StorageException("Storage base path olusturulamadi: {$this->basePath}");
            }
        }
    }

    public function put(string $key, string $contents): void
    {
        $this->assertWritableKey($key);
        $path = $this->resolvePath($key);
        $this->ensureDirectory(dirname($path));

        $tempPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($tempPath, $contents, LOCK_EX) === false) {
            throw new StorageException("Dosya yazilamadi: {$key}");
        }

        if (!rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new StorageException("Dosya tasinamadi: {$key}");
        }

        @chmod($path, 0644);
    }

    public function putFromUpload(string $key, array $uploadedFile): void
    {
        if (!isset($uploadedFile['tmp_name'], $uploadedFile['error'])) {
            throw new StorageException('Gecersiz upload dizisi.');
        }

        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            throw new StorageException('Upload hatasi: ' . $this->uploadErrorMessage((int) $uploadedFile['error']));
        }

        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            throw new StorageException('Gecersiz upload kaynagi.');
        }

        $this->assertWritableKey($key);
        $path = $this->resolvePath($key);
        $this->ensureDirectory(dirname($path));

        if (!move_uploaded_file($uploadedFile['tmp_name'], $path)) {
            throw new StorageException("Upload tasinamadi: {$key}");
        }

        @chmod($path, 0644);
    }

    public function get(string $key): ?string
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    public function stream(string $key, ?string $downloadName = null): void
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            throw new StorageException("Dosya bulunamadi: {$key}");
        }

        $mime = $this->mimeType($key) ?? 'application/octet-stream';
        $size = filesize($path);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Cache-Control: private, max-age=300');

        if ($downloadName !== null) {
            // ASCII fallback + RFC 5987 UTF-8 (filename*) ile Unicode (Türkçe vb.) ad korunur.
            $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
            $utf8  = rawurlencode($downloadName);
            header(
                'Content-Disposition: attachment; filename="' . $ascii . '"; '
                . "filename*=UTF-8''" . $utf8
            );
        }

        readfile($path);
    }

    public function delete(string $key): void
    {
        $path = $this->resolvePath($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->resolvePath($key));
    }

    public function size(string $key): int
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            throw new StorageException("Dosya bulunamadi: {$key}");
        }

        return (int) filesize($path);
    }

    public function mimeType(string $key): ?string
    {
        $path = $this->resolvePath($key);

        if (!is_file($path)) {
            return null;
        }

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

    /**
     * Yazma anında çalıştırılabilir/sunucu-kontrol uzantılarını reddeder.
     * Çift uzantı dahil her segment denetlenir ('a.php.jpg' → 'php' yakalanır;
     * '.htaccess' → 'htaccess' yakalanır). Yalnızca yazma yollarında çağrılır;
     * okuma/silme mevcut dosyaları etkilemesin diye serbesttir.
     */
    private function assertWritableKey(string $key): void
    {
        $base = strtolower(basename(str_replace('\\', '/', $key)));

        if ($base === '.user.ini') {
            throw new StorageException("Sunucu yapilandirma dosyasi yazilamaz: {$key}");
        }

        foreach (explode('.', $base) as $segment) {
            if ($segment !== '' && in_array($segment, self::BLOCKED_WRITE_EXTENSIONS, true)) {
                throw new StorageException("Guvenli olmayan dosya uzantisi, yazma reddedildi: {$key}");
            }
        }
    }

    private function resolvePath(string $key): string
    {
        $key = ltrim($key, '/\\');

        if ($key === '' || str_contains($key, '..') || str_contains($key, "\0")) {
            throw new StorageException("Gecersiz storage key: {$key}");
        }

        $normalized = str_replace('\\', '/', $key);

        if (preg_match('#(^|/)\.\.?(/|$)#', $normalized)) {
            throw new StorageException("Gecersiz storage key: {$key}");
        }

        return rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . $normalized;
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new StorageException("Dizin olusturulamadi: {$dir}");
        }
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => 'Dosya php.ini upload_max_filesize limitini asiyor',
            UPLOAD_ERR_FORM_SIZE  => 'Dosya form MAX_FILE_SIZE limitini asiyor',
            UPLOAD_ERR_PARTIAL    => 'Dosya kismi yuklendi',
            UPLOAD_ERR_NO_FILE    => 'Dosya yuklenmedi',
            UPLOAD_ERR_NO_TMP_DIR => 'Gecici dizin yok',
            UPLOAD_ERR_CANT_WRITE => 'Diske yazilamadi',
            UPLOAD_ERR_EXTENSION  => 'PHP uzantisi upload\'u durdurdu',
            default               => 'Bilinmeyen upload hatasi (' . $code . ')',
        };
    }
}
