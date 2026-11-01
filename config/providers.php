<?php

declare(strict_types=1);

return [
    System\Container\Provider\RuntimeProvider::class,
    System\Container\Provider\LoggingProvider::class,

    System\Container\Provider\HttpProvider::class,

    System\Container\Provider\MonitorProvider::class,
    System\Container\Provider\ErrorHandlingProvider::class,

    System\Container\Provider\DatabaseProvider::class,
    System\Container\Provider\CacheProvider::class,
    System\Container\Provider\ViewProvider::class,
    System\Container\Provider\ValidationProvider::class,
    System\Container\Provider\StorageProvider::class,
    System\Container\Provider\MediaProvider::class,
    System\Container\Provider\SecurityProvider::class,
    System\Container\Provider\RoutingProvider::class,
    System\Container\Provider\ConsoleProvider::class,

    System\Container\Provider\ModuleProvider::class,
];
