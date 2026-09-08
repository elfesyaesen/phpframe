<?php

declare(strict_types=1);

namespace System\Config;

/**
 * Uygulama genel ayarları.
 *
 * `APP_ROOT` dışındaki tüm `APP_*` sabitlerinin yerini alır. `APP_ROOT` sabit
 * olarak KALIR çünkü o config değil bir bootstrap primitive'idir: Autoloader
 * ona herhangi bir obje var olmadan önce ihtiyaç duyar. Buradaki `$root`
 * aynı değerin enjekte edilebilir kopyasıdır — servisler sabite değil bu
 * alana bakar.
 */
final readonly class AppConfig
{
    public function __construct(
        public string $root,
        public string $url,
        public bool $production,
        public string $timezone,
    ) {}

    /** Uygulama köküne göre yol kurar. */
    public function path(string ...$segments): string
    {
        return rtrim($this->root, '/\\')
            . DIRECTORY_SEPARATOR
            . implode(DIRECTORY_SEPARATOR, array_map(
                static fn(string $segment): string => trim($segment, '/\\'),
                $segments
            ));
    }

    /** Sonunda tek bir `/` olacak şekilde normalize edilmiş uygulama URL'i. */
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
