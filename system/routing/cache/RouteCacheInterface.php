<?php

declare(strict_types=1);

namespace System\Routing\Cache;

use System\Routing\RadixTree;

interface RouteCacheInterface
{
    public function get(): ?RadixTree;

    public function set(RadixTree $tree, ?string $hash = null): void;

    public function isValid(?string $hash = null): bool;

    public function clear(): void;

    public function getMeta(): array;
}
