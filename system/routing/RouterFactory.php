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

final class RouterFactory
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly AppConfig $app,
    ) {}

    /**
     * @param array<string, string>|null $controllerPaths
     */
    public function create(
        ?RouteCacheInterface $cache = null,
        ?array $controllerPaths = null,
        ?bool $enableCache = null,
    ): Router {
        $enableCache ??= $this->app->production;

        $cache ??= $enableCache ? $this->detectBestCache() : new NullRouteCache();

        $controllerPaths ??= $this->controllerPaths();

        $scanner = new AttributeScanner($controllerPaths);

        return new Router(
            container: $this->container,
            logger: $this->container->get(LoggerInterface::class),
            app: $this->app,
            http: $this->container->get(HttpConfig::class),
            cache: $cache,
            scanner: $scanner,

            validateControllerMtime: !$enableCache,
        );
    }

    public function detectBestCache(): RouteCacheInterface
    {
        if (ApcuRouteCache::isAvailable()) {
            return new ApcuRouteCache();
        }

        return new FileRouteCache($this->cachePath());
    }

    /**
     * @return array<string, string>
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
     * @return array<string, string>
     */
    public static function getDefaultControllerPaths(string $appRoot): array
    {
        $modules = ModuleRegistry::discover($appRoot);

        $paths = [];

        foreach ($modules as $module) {
            $fullPath = ModuleRegistry::path($module, 'controllers', $appRoot);
            if (is_dir($fullPath)) {
                $paths[$fullPath] = ModuleRegistry::namespace($module, 'Controllers');
            }
        }

        return $paths;
    }

    public static function getCachePath(string $appRoot): string
    {
        $cachePath = $appRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'router';

        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0755, true);
        }

        return $cachePath;
    }

}

final class NullRouteCache implements RouteCacheInterface
{
    public function get(): ?RadixTree
    {
        return null;
    }

    public function set(RadixTree $tree, ?string $hash = null): void
    {
    }

    public function isValid(?string $hash = null): bool
    {
        return false;
    }

    public function clear(): void
    {
    }

    public function getMeta(): array
    {
        return ['driver' => 'null', 'enabled' => false];
    }
}
