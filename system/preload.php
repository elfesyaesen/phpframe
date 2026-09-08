<?php

declare(strict_types=1);

/**
 * OPcache preload script.
 *
 * Production'da php.ini'de `opcache.preload=/path/to/system/preload.php` ile
 * sunucu başlangıcında bir kez çalışır; çekirdek hot-path sınıfları derlenip
 * paylaşımlı belleğe alınır, böylece her request'te dosya derleme maliyeti olmaz.
 *
 * NOT: OPcache preloading Windows'ta DESTEKLENMEZ; bu betik Linux/FPM içindir.
 * Yerelde "tüm referans sınıflar yükleniyor mu" doğrulaması için doğrudan da
 * çalıştırılabilir:  php system/preload.php
 */

require_once __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/system/engine/Autoloader.php';

new System\Engine\Autoloader();

/**
 * Preload edilecek çekirdek sınıflar (kernel + routing + DI + cache + http + db).
 * Twig/PHPMailer gibi ağır vendored kütüphaneler bilinçli dışarıda; istek anında
 * lazy yüklenir.
 *
 * @var array<int, class-string>
 */
$coreClasses = [
    // Engine
    System\Engine\BaseController::class,
    System\Engine\BaseModel::class,
    System\Engine\ControllerServices::class,

    // DI — derlenmiş container'ın çalışma zamanı yüzeyi.
    //
    // Eski `System\Engine\Container` ve `ContainerCompiler` yerine bunlar
    // preload edilir. Derleyici sınıfları (System\Container\Compilation\*)
    // KASITLI OLARAK DIŞARIDA: production'da derleme yapılmaz (DI-plan §32),
    // dolayısıyla o kod hiç yüklenmez.
    System\Container\Compiled\AbstractCompiledContainer::class,
    System\Container\Compiled\CompiledContainerLoader::class,
    System\Container\Contract\ContainerInterface::class,
    System\Container\Contract\ScopeInterface::class,
    System\Container\Core\Scope::class,
    System\Container\Lifetime\Lifetime::class,
    System\Container\Lifetime\ScopeManager::class,
    Psr\Container\ContainerInterface::class,

    // Runtime
    System\Runtime\Kernel::class,
    System\Runtime\Sapi::class,
    System\Runtime\ShutdownRegistry::class,
    System\Runtime\ShutdownPriority::class,
    System\Runtime\RequestId::class,
    System\Runtime\RequestSnapshot::class,
    System\Runtime\RuntimeInstances::class,

    // Typed config (her istekte kurulur — DI-plan §25)
    System\Config\AppConfig::class,
    System\Config\ConfigFactory::class,
    System\Config\DatabaseConfig::class,
    System\Config\EnvReader::class,
    System\Config\HttpConfig::class,
    System\Config\MonitorConfig::class,

    // Routing
    System\Routing\Router::class,
    System\Routing\RadixTree::class,
    System\Routing\RouteMatch::class,
    System\Routing\RouterFactory::class,
    System\Routing\AttributeScanner::class,
    System\Routing\Cache\FileRouteCache::class,
    System\Routing\Cache\ApcuRouteCache::class,
    System\Routing\Cache\RouteCacheInterface::class,

    // Middleware
    System\Middleware\MiddlewareRegistry::class,
    System\Middleware\Pipeline::class,
    System\Middleware\Interface\MiddlewareInterface::class,

    // Cache (Faz 4)
    System\Cache\CacheInterface::class,
    System\Cache\AbstractCache::class,
    System\Cache\RedisCache::class,
    System\Cache\NullCache::class,
    System\Cache\ArrayCache::class,
    System\Cache\CacheFactory::class,

    // HTTP
    System\Http\Request::class,
    System\Http\Response::class,

    // Database
    System\Database\Database::class,
];

$loaded = 0;
$skipped = [];

foreach ($coreClasses as $class) {
    try {
        // autoload=true: autoloader sınıfı çözer ve OPcache preload belleğine alır.
        if (class_exists($class, true) || interface_exists($class, true)) {
            $loaded++;
        } else {
            $skipped[] = $class;
        }
    } catch (\Throwable $e) {
        $skipped[] = $class . ' (' . $e->getMessage() . ')';
    }
}

// Doğrudan çalıştırıldığında (preload değil) özet ver; preload modunda çıktı yok sayılır.
if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "Preload: {$loaded} sınıf yüklendi" . PHP_EOL);
    if ($skipped !== []) {
        fwrite(STDOUT, 'Atlanan: ' . implode(', ', $skipped) . PHP_EOL);
    }
}
