<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$scope = $kernel->beginRequestScope();

$router = $scope->get(System\Routing\Router::class);
$monitorConfig = $scope->get(System\Config\MonitorConfig::class);

require APP_ROOT . '/routes/routes.php';

try {
    $router->dispatch($scope->get(System\Http\Request::class));
} catch (Throwable $e) {
    $container->get(System\Logging\ExceptionHandler::class)->handleException($e);
} finally {
    $kernel->endRequestScope();
}
