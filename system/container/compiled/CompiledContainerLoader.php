<?php

declare(strict_types=1);

namespace System\Container\Compiled;

use System\Container\Compilation\AtomicWriter;
use System\Container\Contract\ContainerInterface;
use System\Container\Exception\CompilationException;
use System\Container\Lifetime\ScopeManager;

final class CompiledContainerLoader
{
    public function __construct(
        private readonly string $cacheDir,
    ) {}

    public static function forRoot(string $appRoot): self
    {
        return new self(self::defaultCacheDir($appRoot));
    }

    public static function defaultCacheDir(string $appRoot): string
    {
        return rtrim($appRoot, '/\\')
            . DIRECTORY_SEPARATOR . 'system'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'container';
    }

    /**
     * @param array<string, object> $externals
     * @param string|null $expectedHash
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

    public function exists(): bool
    {
        $writer = new AtomicWriter($this->cacheDir);
        $pointer = $writer->readPointer();

        return $pointer !== null && is_file($writer->path($pointer['file']));
    }

    public function liveHash(): ?string
    {
        return (new AtomicWriter($this->cacheDir))->readPointer()['hash'] ?? null;
    }

    public function liveHashMode(): ?string
    {
        $mode = (new AtomicWriter($this->cacheDir))->readPointer()['hash_mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }

    /**
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
