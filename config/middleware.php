<?php

declare(strict_types=1);

return [
    'api_auth'       => \App\Api\Middleware\AuthMiddleware::class,
    'api_role'       => \App\Api\Middleware\RoleMiddleware::class,
    'api_superadmin' => \App\Api\Middleware\SuperadminMiddleware::class,
    'api_permission' => \App\Api\Middleware\PermissionMiddleware::class,

    'rate_limit'     => \System\Middleware\RateLimitMiddleware::class,

    'monitor_token'  => \App\Monitor\Middleware\MonitorTokenMiddleware::class,
];
