<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/system/engine/Autoloader.php';

new System\Engine\Autoloader();

/**
 * @var array<int, class-string>
 */
$coreClasses = [
    System\Engine\BaseController::class,
    System\Engine\BaseModel::class,
    System\Engine\ControllerServices::class,

    System\Container\Core\Container::class,
    System\Container\Core\ContainerBuilder::class,
    System\Container\Resolution\AutowireResolver::class,
    System\Container\Resolution\ParameterResolver::class,
    System\Container\Resolution\ReflectionCache::class,
    System\Container\Provider\ModuleProvider::class,
    System\Container\Contract\ContainerInterface::class,
    System\Container\Contract\ScopeInterface::class,
    System\Container\Core\Scope::class,
    System\Container\Lifetime\Lifetime::class,
    System\Container\Lifetime\ScopeManager::class,
    Psr\Container\ContainerInterface::class,

    System\Runtime\Kernel::class,
    System\Runtime\Sapi::class,
    System\Runtime\ShutdownRegistry::class,
    System\Runtime\ShutdownPriority::class,
    System\Runtime\RequestId::class,
    System\Runtime\RequestSnapshot::class,
    System\Runtime\RuntimeInstances::class,

    System\Config\AppConfig::class,
    System\Config\ConfigFactory::class,
    System\Config\DatabaseConfig::class,
    System\Config\EnvReader::class,
    System\Config\HttpConfig::class,
    System\Config\MonitorConfig::class,

    System\Routing\Router::class,
    System\Routing\RadixTree::class,
    System\Routing\RouteMatch::class,
    System\Routing\RouterFactory::class,
    System\Routing\AttributeScanner::class,
    System\Routing\Cache\FileRouteCache::class,
    System\Routing\Cache\ApcuRouteCache::class,
    System\Routing\Cache\RouteCacheInterface::class,

    System\Middleware\MiddlewareRegistry::class,
    System\Middleware\Pipeline::class,
    System\Middleware\Interface\MiddlewareInterface::class,

    System\Cache\CacheInterface::class,
    System\Cache\AbstractCache::class,
    System\Cache\RedisCache::class,
    System\Cache\NullCache::class,
    System\Cache\ArrayCache::class,
    System\Cache\CacheFactory::class,

    System\Http\Request::class,
    System\Http\Response::class,

    System\Database\Database::class,
];

$loaded = 0;
$skipped = [];

foreach ($coreClasses as $class) {
    try {
        if (class_exists($class, true) || interface_exists($class, true)) {
            $loaded++;
        } else {
            $skipped[] = $class;
        }
    } catch (\Throwable $e) {
        $skipped[] = $class . ' (' . $e->getMessage() . ')';
    }
}

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "Preload: {$loaded} sınıf yüklendi" . PHP_EOL);
    if ($skipped !== []) {
        fwrite(STDOUT, 'Atlanan: ' . implode(', ', $skipped) . PHP_EOL);
    }
}
