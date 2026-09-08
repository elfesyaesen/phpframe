<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use RuntimeException;

/**
 * Derleme çıktısını atomik olarak diske yazar (DI-plan §30).
 *
 * SIRA KRİTİK: sınıf ve metadata dosyaları önce yazılır, POINTER EN SON.
 * Böylece eşzamanlı bir okuyucu ya eski tam çifti ya yeni tam çifti görür,
 * asla yırtık bir durum görmez. Pointer önce yazılırsa, henüz var olmayan bir
 * sınıf dosyasına işaret eden bir pointer üretilirdi.
 *
 * Her dosya tmp + rename ile yazılır (rename aynı dosya sisteminde atomiktir)
 * ve ardından opcache_invalidate edilir — aksi halde OPcache eski byte kodunu
 * sunmaya devam eder.
 */
final class AtomicWriter
{
    public const POINTER_FILE = 'container.php';

    public function __construct(
        private readonly string $cacheDir,
    ) {}

    /** Kaynak parmak izi modu — pointer'a yazılır, bkz. write(). */
    public const HASH_MODE_MTIME = 'mtime';
    public const HASH_MODE_CONTENT = 'content';

    /**
     * @param array<string, mixed> $metadata
     * @param self::HASH_MODE_* $hashMode
     *        Hash'in HANGİ kaynak parmak izi moduyla üretildiği.
     *
     *        Pointer'a yazılmak ZORUNDA: `CacheHash` iki mod sunuyor
     *        (dev'de mtime+size, `--ci`'da sha1_file) ve bu iki mod aynı
     *        kaynak ağacı için FARKLI hash üretir. Modu kaydetmezsek
     *        bayatlık kontrolü hangi modla karşılaştıracağını bilemez ve
     *        taze bir derlemeyi "bayat" sanar — `app:health` tam bu yüzden
     *        her zaman BAYAT diyordu (production'da FAIL, yani doğru bir
     *        deploy'u kıran sahte alarm).
     *
     * @return array{class: string, metadata: string, pointer: string}
     *         Yazılan dosya yolları
     */
    public function write(
        string $className,
        string $hash,
        string $source,
        array $metadata,
        string $hashMode = self::HASH_MODE_MTIME,
    ): array {
        $this->ensureDirectory();

        $classFile = $this->path('CompiledContainer.' . $hash . '.php');
        $metaFile = $this->path('metadata.' . $hash . '.php');
        $pointerFile = $this->path(self::POINTER_FILE);

        // 1-2. İçerik dosyaları.
        $this->writeFile($classFile, $source);
        $this->writeFile($metaFile, $this->renderReturn($metadata, 'Derleme metadata\'sı — YALNIZCA CLI okur, istek asla.'));

        // 3. Pointer EN SON: bu, yeni derlemeyi "canlı" yapan atomik adım.
        $this->writeFile($pointerFile, $this->renderReturn([
            'hash' => $hash,
            'hash_mode' => $hashMode,
            'class' => $className,
            'file' => basename($classFile),
            'metadata' => basename($metaFile),
            'generated' => date('c'),
        ], 'Canlı derlemeye işaret eder. Önceki derleme diskte kalır (rollback).'));

        return [
            'class' => $classFile,
            'metadata' => $metaFile,
            'pointer' => $pointerFile,
        ];
    }

    /**
     * Canlı derlemenin pointer'ını okur.
     *
     * @return array{hash: string, class: string, file: string, hash_mode?: string, metadata?: string, generated?: string}|null
     *         `hash_mode` bu alan eklenmeden önce yazılmış pointer'larda
     *         YOKTUR; okuyucu onu opsiyonel saymak zorunda.
     */
    public function readPointer(): ?array
    {
        $file = $this->path(self::POINTER_FILE);

        if (!is_file($file)) {
            return null;
        }

        /** @var mixed $pointer */
        $pointer = require $file;

        if (!is_array($pointer) || !isset($pointer['hash'], $pointer['class'], $pointer['file'])) {
            return null;
        }

        /** @var array{hash: string, class: string, file: string} $pointer */
        return $pointer;
    }

    public function path(string $file): string
    {
        return rtrim($this->cacheDir, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Canlı olmayan eski derlemeleri siler.
     *
     * `$keep` kadar önceki derleme bırakılır: hızlı rollback için pointer'ı
     * geri çevirmek yeterli olsun.
     *
     * @return list<string> Silinen dosyalar
     */
    public function prune(int $keep = 1): array
    {
        $pointer = $this->readPointer();
        $live = $pointer['hash'] ?? null;

        $groups = [];
        foreach (glob($this->path('CompiledContainer.*.php')) ?: [] as $file) {
            if (preg_match('/CompiledContainer\.([0-9a-f]+)\.php$/', $file, $m) === 1) {
                $groups[$m[1]] = (int) @filemtime($file);
            }
        }

        if ($live !== null) {
            unset($groups[$live]);
        }

        arsort($groups); // en yeni önce

        $removed = [];
        foreach (array_slice(array_keys($groups), $keep) as $hash) {
            foreach (['CompiledContainer.' . $hash . '.php', 'metadata.' . $hash . '.php'] as $name) {
                $file = $this->path($name);
                if (is_file($file) && @unlink($file)) {
                    $removed[] = $file;
                    if (function_exists('opcache_invalidate')) {
                        opcache_invalidate($file, true);
                    }
                }
            }
        }

        return $removed;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->cacheDir)) {
            return;
        }

        if (!@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException("Derleme dizini oluşturulamadı: {$this->cacheDir}");
        }
    }

    private function writeFile(string $file, string $content): void
    {
        $temp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temp, $content, LOCK_EX) === false) {
            throw new RuntimeException("Yazılamadı: {$temp}");
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);
            throw new RuntimeException("Taşınamadı: {$file}");
        }

        @chmod($file, 0644);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderReturn(array $data, string $note): string
    {
        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "// `php frame container:compile` tarafından üretildi — elle düzenlemeyin.\n"
            . '// ' . $note . "\n\n"
            . 'return ' . var_export($data, true) . ";\n";
    }
}
