<?php

declare(strict_types=1);

namespace System\Config;

final readonly class AppConfig
{
    public function __construct(
        public string $root,
        public string $url,
        public bool $production,
        public string $timezone,
    ) {}

    public function path(string ...$segments): string
    {
        return rtrim($this->root, '/\\')
            . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, array_map(
                static fn(string $segment): string => trim($segment, '/\\'),
                $segments
            ));
    }

    public function langPath(): string
    {
        return $this->path('app', 'lang');
    }

    public function cachePath(string ...$segments): string
    {
        return $this->path('storage', 'cache', ...$segments);
    }

    public function url(string $path = ''): string
    {
        $base = rtrim($this->url, '/') . '/';

        return $path === '' ? $base : $base . ltrim($path, '/');
    }

    public function isDebug(): bool
    {
        return !$this->production;
    }
}
