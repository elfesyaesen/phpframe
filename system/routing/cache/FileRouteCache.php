<?php

declare(strict_types=1);

namespace System\Routing\Cache;

use System\Routing\RadixTree;
use RuntimeException;

final class FileRouteCache implements RouteCacheInterface
{
    private const CACHE_FILE = 'routes.cache.php';
    private const META_FILE = 'routes.meta.php';

    public function __construct(
        private readonly string $cachePath,
    ) {
        if (!is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0755, true);
        }
    }

    public function get(): ?RadixTree
    {
        $cacheFile = $this->getCacheFilePath();

        if (!file_exists($cacheFile)) {
            return null;
        }

        try {
            $data = require $cacheFile;

            if (!is_array($data)) {
                return null;
            }

            return RadixTree::fromArray($data);
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(RadixTree $tree, ?string $hash = null): void
    {
        $cacheFile = $this->getCacheFilePath();
        $metaFile = $this->getMetaFilePath();
        $tempFile = $cacheFile . '.' . uniqid('', true) . '.tmp';

        $data = $tree->toArray();

        // OPcache-friendly PHP export
        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "// Generated: " . date('Y-m-d H:i:s') . "\n";
        $content .= "// Hash: " . ($hash ?? 'none') . "\n";
        $content .= "// Routes: " . $tree->count() . "\n\n";
        $content .= "return " . var_export($data, true) . ";\n";

        // Atomic write: write to temp, then rename
        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write route cache to: {$tempFile}");
        }

        if (!rename($tempFile, $cacheFile)) {
            @unlink($tempFile);
            throw new RuntimeException("Failed to move route cache to: {$cacheFile}");
        }

        // Clear OPcache for this file
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cacheFile, true);
        }

        // Write metadata
        $meta = [
            'hash' => $hash,
            'created_at' => time(),
            'route_count' => $tree->count(),
        ];

        file_put_contents(
            $metaFile,
            "<?php\nreturn " . var_export($meta, true) . ";\n",
            LOCK_EX
        );
    }

    public function isValid(?string $hash = null): bool
    {
        $cacheFile = $this->getCacheFilePath();

        if (!file_exists($cacheFile)) {
            return false;
        }

        if ($hash === null) {
            return true;
        }

        $metaFile = $this->getMetaFilePath();

        if (!file_exists($metaFile)) {
            return false;
        }

        try {
            $meta = require $metaFile;
            return ($meta['hash'] ?? null) === $hash;
        } catch (\Throwable) {
            return false;
        }
    }

    public function clear(): void
    {
        $cacheFile = $this->getCacheFilePath();
        $metaFile = $this->getMetaFilePath();

        if (file_exists($cacheFile)) {
            @unlink($cacheFile);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($cacheFile, true);
            }
        }

        if (file_exists($metaFile)) {
            @unlink($metaFile);
        }
    }

    public function getMeta(): array
    {
        $metaFile = $this->getMetaFilePath();

        $meta = [
            'driver' => 'file',
            'path' => $this->cachePath,
            'exists' => file_exists($this->getCacheFilePath()),
        ];

        if (file_exists($metaFile)) {
            try {
                $stored = require $metaFile;
                if (is_array($stored)) {
                    $meta = [...$meta, ...$stored];
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $meta;
    }

    private function getCacheFilePath(): string
    {
        return rtrim($this->cachePath, '/\\') . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    private function getMetaFilePath(): string
    {
        return rtrim($this->cachePath, '/\\') . DIRECTORY_SEPARATOR . self::META_FILE;
    }
}
