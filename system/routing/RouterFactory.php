<?php

declare(strict_types=1);

namespace System\Routing;

use Psr\Container\ContainerInterface;
use System\Config\AppConfig;
use System\Config\HttpConfig;
use System\Engine\ModuleRegistry;
use System\Logging\Contracts\LoggerInterface;
use System\Routing\Cache\RouteCacheInterface;
use System\Routing\Cache\ApcuRouteCache;
use System\Routing\Cache\FileRouteCache;

/**
 * Router kurar.
 *
 * Artık STATIC DEĞİL: `AppConfig` ve container enjekte alır. Eskiden
 * `defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)` biçiminde iki yerde
 * sabit okuyordu — sabit tanımlı değilse dizin yapısına dayanan bir tahmine
 * düşüyordu, yani "hangi kökü kullanıyorum" sorusunun cevabı çağrı bağlamına
 * göre değişebiliyordu.
 */
final class RouterFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly AppConfig $app,
    ) {}

    /**
     * Tam yapılandırılmış Router örneği.
     *
     * @param array<string, string>|null $controllerPaths dizin => namespace
     */
    public function create(
        ?RouteCacheInterface $cache = null,
        ?array $controllerPaths = null,
        ?bool $enableCache = null,
    ): Router {
        // Route cache production'da açık, dev'de kapalı — router'ın kendi
        // konvansiyonu değişmedi, yalnızca kaynağı sabit değil config.
        $enableCache ??= $this->app->production;

        // Auto-detect best cache driver if not provided
        $cache ??= $enableCache ? $this->detectBestCache() : new NullRouteCache();

        // Default controller paths if not provided
        $controllerPaths ??= $this->controllerPaths();

        $scanner = new AttributeScanner($controllerPaths);

        return new Router(
            container: $this->container,
            logger: $this->container->get(LoggerInterface::class),
            app: $this->app,
            http: $this->container->get(HttpConfig::class),
            cache: $cache,
            scanner: $scanner,
            // Production (enableCache=true) → controller mtime taramasını atla;
            // dev → otomatik algılama için tara.
            validateControllerMtime: !$enableCache,
        );
    }

    public function detectBestCache(): RouteCacheInterface
    {
        // Priority 1: APCu (works on both Windows and Linux, shared memory)
        if (self::isApcuAvailable()) {
            return new ApcuRouteCache();
        }

        // Priority 2: File cache (universal fallback, OPcache-friendly)
        return new FileRouteCache($this->cachePath());
    }

    /**
     * @return array<string, string> dizin => namespace
     */
    public function controllerPaths(): array
    {
        return self::getDefaultControllerPaths($this->app->root);
    }

    public function cachePath(): string
    {
        return self::getCachePath($this->app->root);
    }

    /**
     * Get default controller paths based on common framework structure
     *
     * SAF kaldı (static + explicit $appRoot): CLI komutları ve derleyicinin
     * kök toplayıcısı bunu container kurmadan çağırabilmeli.
     *
     * @return array<string, string> dizin => namespace
     */
    public static function getDefaultControllerPaths(string $appRoot): array
    {
        $modules = ModuleRegistry::discover($appRoot);

        $paths = [];

        // Modüller dosya sisteminden keşfedilir (ModuleRegistry): ne .env listesi
        // ne sabit whitelist. Yeni bir modül dizini açmak onu route taramasına
        // dahil etmek için yeterlidir.
        // Dizin adlari kucuk harf (api/controllers); Linux case-sensitive FS'te
        // is_dir() eslesmesi icin path'ler gercek yapiya gore kucuk harf olmali.
        // Namespace'ler PascalCase kalir (ucfirst); autoloader namespace yolunu kucuk harfe cevirir.
        foreach ($modules as $module) {
            $fullPath = $appRoot . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'controllers';
            if (is_dir($fullPath)) {
                $paths[$fullPath] = ucfirst($module) . '\\Controllers';
            }
        }

        return $paths;
    }

    /**
     * Get cache storage path
     *
     * SAF kaldı (static + explicit $appRoot): `cache:clear` / `cache:warm`
     * komutları container kurmadan da yolu bilmek isteyebilir.
     */
    public static function getCachePath(string $appRoot): string
    {
        $cachePath = $appRoot . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'router';

        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0755, true);
        }

        return $cachePath;
    }

    /**
     * Check if APCu is available and enabled
     */
    private static function isApcuAvailable(): bool
    {
        if (!function_exists('apcu_store') || !function_exists('apcu_enabled')) {
            return false;
        }

        /** @phpstan-ignore-next-line */
        return @call_user_func('apcu_enabled');
    }
}

/**
 * Null cache implementation for development/debugging
 */
final class NullRouteCache implements RouteCacheInterface
{
    public function get(): ?RadixTree
    {
        return null;
    }

    public function set(RadixTree $tree, ?string $hash = null): void
    {
        // No-op
    }

    public function isValid(?string $hash = null): bool
    {
        return false;
    }

    public function clear(): void
    {
        // No-op
    }

    public function getMeta(): array
    {
        return ['driver' => 'null', 'enabled' => false];
    }
}
