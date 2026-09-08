<?php

declare(strict_types=1);

namespace System\Routing\Cache;

use System\Routing\RadixTree;

interface RouteCacheInterface
{
    /**
     * Get cached route tree
     */
    public function get(): ?RadixTree;

    /**
     * Store route tree in cache
     */
    public function set(RadixTree $tree, ?string $hash = null): void;

    /**
     * Check if cache exists and is valid
     */
    public function isValid(?string $hash = null): bool;

    /**
     * Clear the cache
     */
    public function clear(): void;

    /**
     * Get cache metadata (for debugging)
     */
    public function getMeta(): array;
}
