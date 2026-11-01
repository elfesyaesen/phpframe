<?php

declare(strict_types=1);

/**
 * @var \System\Routing\Router $router
 */

$router->get('/', [\App\Api\Controllers\HomeController::class, 'index'])->name('home');
$router->get('/v1/api', [\App\Api\Controllers\HomeController::class, 'index'])->name('api.index');

$router->group(['prefix' => '/v1/api'], function ($router) {
    $router->post('/auth/login',   [\App\Api\Controllers\AuthController::class, 'login'])->name('auth.login')->middleware('rate_limit:5,60');
    $router->post('/auth/refresh', [\App\Api\Controllers\AuthController::class, 'refresh'])->name('auth.refresh')->middleware('rate_limit:10,60');
    $router->post('/users/register',       [\App\Api\Controllers\UserController::class, 'register'])->name('users.register')->middleware('rate_limit:3,3600');
    $router->post('/users/reset-password', [\App\Api\Controllers\UserController::class, 'resetPassword'])->name('users.reset-password')->middleware('rate_limit:3,3600');
    $router->post('/users/reset-password/confirm', [\App\Api\Controllers\UserController::class, 'confirmResetPassword'])->name('users.reset-password.confirm')->middleware('rate_limit:10,600');
    $router->post('/users/reset-password/verify-code', [\App\Api\Controllers\UserController::class, 'confirmResetPasswordWithCode'])->name('users.reset-password.verify-code')->middleware('rate_limit:5,600');
});

$router->get('/media/p/{uuid}/{file}', [\App\Media\Controllers\MediaController::class, 'publicFile'])->name('media.public');
$router->get('/media/s/{uuid}/{file}', [\App\Media\Controllers\MediaController::class, 'signedFile'])->name('media.signed');

$router->group(['prefix' => '/v1/api', 'middleware' => ['api_auth', 'rate_limit']], function ($router) {

    $router->post('/auth/logout', [\App\Api\Controllers\AuthController::class, 'logout'])->name('auth.logout');
    $router->get('/auth/me',      [\App\Api\Controllers\AuthController::class, 'me'])->name('auth.me');

    $router->post('/users/change-password', [\App\Api\Controllers\UserController::class, 'changePassword'])->name('users.change-password')->middleware('rate_limit:5,300');
    $router->get('/users/me',               [\App\Api\Controllers\UserController::class, 'me'])->name('users.me');
    $router->put('/users/me',               [\App\Api\Controllers\UserController::class, 'updateProfile'])->name('users.profile.update');
    $router->delete('/users/me',            [\App\Api\Controllers\UserController::class, 'deleteAccount'])->name('users.delete')->middleware('rate_limit:5,300');

    $router->post('/users/me/avatar',   [\App\Api\Controllers\UserController::class, 'uploadAvatar'])->name('users.avatar.upload');
    $router->get('/users/me/avatar',    [\App\Api\Controllers\UserController::class, 'serveAvatar'])->name('users.avatar.serve');
    $router->delete('/users/me/avatar', [\App\Api\Controllers\UserController::class, 'deleteAvatar'])->name('users.avatar.delete');

    $router->post('/media',          [\App\Media\Controllers\MediaApiController::class, 'store'])->name('media.store')->middleware('rate_limit:20,60');
    $router->get('/media/{uuid}',    [\App\Media\Controllers\MediaApiController::class, 'show'])->name('media.show');
    $router->delete('/media/{uuid}', [\App\Media\Controllers\MediaApiController::class, 'destroy'])->name('media.destroy');
});

$router->group(['prefix' => '/v1/api/admin', 'middleware' => ['api_auth', 'api_superadmin', 'rate_limit']], function ($router) {
    $router->get('/roles',                    [\App\Api\Controllers\RoleController::class, 'index'])->name('admin.roles.index');
    $router->post('/roles',                   [\App\Api\Controllers\RoleController::class, 'store'])->name('admin.roles.store');
    $router->put('/roles/{uuid}',             [\App\Api\Controllers\RoleController::class, 'update'])->name('admin.roles.update');
    $router->delete('/roles/{uuid}',          [\App\Api\Controllers\RoleController::class, 'destroy'])->name('admin.roles.destroy');
    $router->put('/roles/{uuid}/permissions', [\App\Api\Controllers\RoleController::class, 'syncPermissions'])->name('admin.roles.permissions');

    $router->get('/permissions',           [\App\Api\Controllers\PermissionController::class, 'index'])->name('admin.permissions.index');
    $router->post('/permissions',          [\App\Api\Controllers\PermissionController::class, 'store'])->name('admin.permissions.store');
    $router->delete('/permissions/{uuid}', [\App\Api\Controllers\PermissionController::class, 'destroy'])->name('admin.permissions.destroy');

    $router->put('/users/{user_uuid}/role',        [\App\Api\Controllers\RoleController::class, 'assignToUser'])->name('admin.users.role');
    $router->put('/users/{user_uuid}/permissions', [\App\Api\Controllers\PermissionController::class, 'setUserOverride'])->name('admin.users.permissions');
});

/** @var \System\Config\MonitorConfig $monitorConfig */
if ($monitorConfig->enabled) {
    $router->group(['prefix' => '/monitor', 'middleware' => ['rate_limit:60,60', 'monitor_token']], function ($router) {
        $router->get('/', [\App\Monitor\Controllers\MonitorController::class, 'index'])->name('monitor.index');
        $router->get('/requests', [\App\Monitor\Controllers\MonitorController::class, 'requests'])->name('monitor.requests');
        $router->get('/live', [\App\Monitor\Controllers\MonitorController::class, 'live'])->name('monitor.live');
        $router->get('/requests/{request_id}', [\App\Monitor\Controllers\MonitorController::class, 'show'])->name('monitor.show');
    });
}
