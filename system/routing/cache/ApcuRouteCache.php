<?php

declare(strict_types=1);

namespace System\Routing\Cache;

use System\Routing\RadixTree;

final class ApcuRouteCache implements RouteCacheInterface
{
    private const CACHE_KEY = 'route_tree';
    private const META_KEY = 'route_tree_meta';

    public function __construct(
        private readonly string $prefix = 'app_routes_',
        private readonly int $ttl = 0, // 0 = forever until restart
    ) {}

    public function get(): ?RadixTree
    {
        if (!$this->isApcuAvailable()) {
            return null;
        }

        $success = false;
        $data = apcu_fetch($this->prefix . self::CACHE_KEY, $success);

        if (!$success || !is_array($data)) {
            return null;
        }

        return RadixTree::fromArray($data);
    }

    public function set(RadixTree $tree, ?string $hash = null): void
    {
        if (!$this->isApcuAvailable()) {
            return;
        }

        $data = $tree->toArray();

        apcu_store($this->prefix . self::CACHE_KEY, $data, $this->ttl);
        apcu_store($this->prefix . self::META_KEY, [
            'hash' => $hash,
            'created_at' => time(),
            'route_count' => $tree->count(),
        ], $this->ttl);
    }

    public function isValid(?string $hash = null): bool
    {
        if (!$this->isApcuAvailable()) {
            return false;
        }

        if (!apcu_exists($this->prefix . self::CACHE_KEY)) {
            return false;
        }

        if ($hash === null) {
            return true;
        }

        $success = false;
        $meta = apcu_fetch($this->prefix . self::META_KEY, $success);

        if (!$success || !is_array($meta)) {
            return false;
        }

        return ($meta['hash'] ?? null) === $hash;
    }

    public function clear(): void
    {
        if (!$this->isApcuAvailable()) {
            return;
        }

        apcu_delete($this->prefix . self::CACHE_KEY);
        apcu_delete($this->prefix . self::META_KEY);
    }

    public function getMeta(): array
    {
        if (!$this->isApcuAvailable()) {
            return ['driver' => 'apcu', 'available' => false];
        }

        $success = false;
        $meta = apcu_fetch($this->prefix . self::META_KEY, $success);

        return [
            'driver' => 'apcu',
            'available' => true,
            'exists' => apcu_exists($this->prefix . self::CACHE_KEY),
            ...($success && is_array($meta) ? $meta : []),
        ];
    }

    private function isApcuAvailable(): bool
    {
        return function_exists('apcu_store')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }
}
