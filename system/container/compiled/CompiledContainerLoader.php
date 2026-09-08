<?php

declare(strict_types=1);

namespace System\Container\Compiled;

use System\Container\Compilation\AtomicWriter;
use System\Container\Contract\ContainerInterface;
use System\Container\Exception\CompilationException;
use System\Container\Lifetime\ScopeManager;

/**
 * Canlı derlenmiş container'ı yükler.
 *
 * NEDEN POINTER ÜZERİNDEN: derlenmiş sınıfın adı hash sonekli olduğu için
 * bootstrap onu literal adla referans EDEMEZ. Sabit bir ad kullanmak cazip
 * görünür ama rolling deploy'da sıcak bir worker eski sınıfı tutarken yeni
 * dosya require edilir → production'da kurtarılamaz "duplicate class" fatal'i.
 * Pointer bu sorunu tamamen ortadan kaldırır.
 *
 * NEDEN İSTEK BAŞINA HASH HESAPLANMAZ: 150 dosyayı her istekte stat'lamak,
 * derlemenin varlık sebebini yok eder. Bayatlık kontrolü DEPLOY KAPISINDA
 * yapılır (`container:validate --check`, DI-plan §32). Burada yalnızca
 * isteğe bağlı, açıkça verilen bir hash ile karşılaştırma yapılır.
 */
final class CompiledContainerLoader
{
    public function __construct(
        private readonly string $cacheDir,
    ) {}

    public static function forRoot(string $appRoot): self
    {
        return new self(self::defaultCacheDir($appRoot));
    }

    /**
     * Web root'un DIŞINDA (DI-plan §30): web root `public/`, bu dizin
     * HTTP üzerinden erişilemez.
     */
    public static function defaultCacheDir(string $appRoot): string
    {
        return rtrim($appRoot, '/\\')
            . DIRECTORY_SEPARATOR . 'system'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'container';
    }

    /**
     * Derlenmiş container'ı kurar.
     *
     * @param array<string, object> $externals instance() ile verilen canlı objeler
     * @param string|null $expectedHash Verilirse bayatlık kontrolü yapılır
     */
    public function load(
        array $externals = [],
        ?ScopeManager $scopes = null,
        ?string $expectedHash = null,
    ): ContainerInterface {
        $writer = new AtomicWriter($this->cacheDir);
        $pointer = $writer->readPointer();

        if ($pointer === null) {
            throw CompilationException::notCompiled($writer->path(AtomicWriter::POINTER_FILE));
        }

        $classFile = $writer->path($pointer['file']);

        if (!is_file($classFile)) {
            throw CompilationException::notCompiled($classFile);
        }

        if ($expectedHash !== null && $expectedHash !== $pointer['hash']) {
            throw CompilationException::stale($pointer['hash'], $expectedHash);
        }

        $class = $pointer['class'];

        if (!class_exists($class, false)) {
            require $classFile;
        }

        if (!class_exists($class, false)) {
            throw CompilationException::notCompiled($classFile);
        }

        /** @var class-string<AbstractCompiledContainer> $class */
        $container = new $class($externals, $scopes);

        return $container;
    }

    /** Derlenmiş container var mı (production ön kontrolü)? */
    public function exists(): bool
    {
        $writer = new AtomicWriter($this->cacheDir);
        $pointer = $writer->readPointer();

        return $pointer !== null && is_file($writer->path($pointer['file']));
    }

    /** Canlı derlemenin hash'i, yoksa null. */
    public function liveHash(): ?string
    {
        return (new AtomicWriter($this->cacheDir))->readPointer()['hash'] ?? null;
    }

    /**
     * Canlı derlemenin hash'i HANGİ kaynak parmak izi moduyla üretildi?
     *
     * Bayatlık kontrolü karşılaştırmayı aynı modla yapmak ZORUNDA: dev
     * (mtime+size) ve `--ci` (sha1_file) modları aynı kaynak ağacı için
     * farklı hash üretir, dolayısıyla mod karıştırıldığında taze bir
     * derleme "bayat" görünür.
     *
     * Bu alan pointer'a sonradan eklendi; eski bir pointer'da yoksa null
     * döner ve çağıran modun bilinmediğini varsaymak zorundadır.
     */
    public function liveHashMode(): ?string
    {
        $mode = (new AtomicWriter($this->cacheDir))->readPointer()['hash_mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }

    /**
     * Metadata (graf, lifetime'lar, diagnostic'ler) — YALNIZCA CLI okur.
     *
     * @return array<string, mixed>|null
     */
    public function metadata(): ?array
    {
        $writer = new AtomicWriter($this->cacheDir);
        $pointer = $writer->readPointer();

        if ($pointer === null || !isset($pointer['metadata'])) {
            return null;
        }

        $file = $writer->path($pointer['metadata']);

        if (!is_file($file)) {
            return null;
        }

        /** @var mixed $metadata */
        $metadata = require $file;

        return is_array($metadata) ? $metadata : null;
    }

    public function cacheDir(): string
    {
        return $this->cacheDir;
    }
}
