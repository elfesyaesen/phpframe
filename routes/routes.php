<?php

declare(strict_types=1);

/**
 * Tum HTTP route tanimlari. public/index.php tarafindan include edilir.
 * Scope'da $router (System\Routing\Router) bulunur.
 *
 * @var \System\Routing\Router $router
 */

/* API ROUTING */
$router->get('/', [\Api\Controllers\HomeController::class, 'index'])->name('home');
$router->get('/v1/api', [\Api\Controllers\HomeController::class, 'index'])->name('api.index');

// ══════════════════════════════════════════
//  PUBLIC ROUTES (no auth required)
// ══════════════════════════════════════════
$router->group(['prefix' => '/v1/api'], function ($router) {
    // Auth — login + refresh sadece public (refresh kendi refresh-token logic'i ile).
    // Brute-force/credential-stuffing'e karşı IP+route bazlı rate limit (saniye penceresi).
    $router->post('/auth/login',   [\Api\Controllers\AuthController::class, 'login'])->name('auth.login')->middleware('rate_limit:5,60');
    $router->post('/auth/refresh', [\Api\Controllers\AuthController::class, 'refresh'])->name('auth.refresh')->middleware('rate_limit:10,60');

    // Anonymous user actions
    $router->post('/users/register',       [\Api\Controllers\UserController::class, 'register'])->name('users.register')->middleware('rate_limit:3,3600');
    $router->post('/users/reset-password', [\Api\Controllers\UserController::class, 'resetPassword'])->name('users.reset-password')->middleware('rate_limit:3,3600');

    // Sıfırlama linkindeki token'ın tüketildiği yer.
    //
    // Rate limit talep ucundan (3/saat) GEVŞEK, `login`'den (5/dk) sıkı.
    // Gerekçe: token 256 bit CSPRNG, yani kaba kuvvetle bulunamaz —
    // buradaki limit tahmin saldırısına değil, geçerli bir linki elinde
    // tutanın hatalı parola denemelerine ve gürültüye karşı. Talep ucu
    // kadar sıkı olsaydı, parolası politikaya uymayan kullanıcı 3 denemede
    // kilitlenip linki boşa harcardı.
    $router->post('/users/reset-password/confirm', [\Api\Controllers\UserController::class, 'confirmResetPassword'])->name('users.reset-password.confirm')->middleware('rate_limit:10,600');

    // OTP yolu — mail'deki 6 haneli kod.
    //
    // Rate limit link ucundan SIKI (10/10dk yerine 5/10dk) çünkü kod uzayı
    // 10^6 ve link'in 2^256'sıyla kıyaslanamaz. Yine de asıl savunma bu
    // DEĞİL: IP döndürerek aşılabilir. Kaba kuvveti kapatan şey token'ın
    // kendisindeki `attempts` sayacı (PASSWORD_RESET_MAX_ATTEMPTS) — buradaki
    // limit yalnızca gürültüyü kesiyor.
    $router->post('/users/reset-password/verify-code', [\Api\Controllers\UserController::class, 'confirmResetPasswordWithCode'])->name('users.reset-password.verify-code')->middleware('rate_limit:5,600');
});

// ══════════════════════════════════════════
//  PROTECTED ROUTES (api_auth middleware)
// ══════════════════════════════════════════
$router->group(['prefix' => '/v1/api', 'middleware' => ['api_auth']], function ($router) {
    // Auth — logout/me (middleware token dogrulamasini halleder)
    $router->post('/auth/logout', [\Api\Controllers\AuthController::class, 'logout'])->name('auth.logout');
    $router->get('/auth/me',      [\Api\Controllers\AuthController::class, 'me'])->name('auth.me');

    // User profile / password
    $router->post('/users/change-password', [\Api\Controllers\UserController::class, 'changePassword'])->name('users.change-password');
    $router->get('/users/me',               [\Api\Controllers\UserController::class, 'me'])->name('users.me');
    $router->put('/users/me',               [\Api\Controllers\UserController::class, 'updateProfile'])->name('users.profile.update');
    $router->delete('/users/me',            [\Api\Controllers\UserController::class, 'deleteAccount'])->name('users.delete');

    // User avatar
    $router->post('/users/me/avatar',   [\Api\Controllers\UserController::class, 'uploadAvatar'])->name('users.avatar.upload');
    $router->get('/users/me/avatar',    [\Api\Controllers\UserController::class, 'serveAvatar'])->name('users.avatar.serve');
    $router->delete('/users/me/avatar', [\Api\Controllers\UserController::class, 'deleteAvatar'])->name('users.avatar.delete');
});

// ══════════════════════════════════════════
//  ADMIN ROUTES (api_auth + api_role:administrator)
//  Rol & yetki yönetimi — çalışma zamanında eklenip çıkarılabilir.
// ══════════════════════════════════════════
$router->group(['prefix' => '/v1/api/admin', 'middleware' => ['api_auth', 'api_role:administrator']], function ($router) {
    // Roller
    $router->get('/roles',                    [\Api\Controllers\RoleController::class, 'index'])->name('admin.roles.index');
    $router->post('/roles',                   [\Api\Controllers\RoleController::class, 'store'])->name('admin.roles.store');
    $router->put('/roles/{uuid}',             [\Api\Controllers\RoleController::class, 'update'])->name('admin.roles.update');
    $router->delete('/roles/{uuid}',          [\Api\Controllers\RoleController::class, 'destroy'])->name('admin.roles.destroy');
    $router->put('/roles/{uuid}/permissions', [\Api\Controllers\RoleController::class, 'syncPermissions'])->name('admin.roles.permissions');

    // Yetkiler
    $router->get('/permissions',           [\Api\Controllers\PermissionController::class, 'index'])->name('admin.permissions.index');
    $router->post('/permissions',          [\Api\Controllers\PermissionController::class, 'store'])->name('admin.permissions.store');
    $router->delete('/permissions/{uuid}', [\Api\Controllers\PermissionController::class, 'destroy'])->name('admin.permissions.destroy');

    // Kullanıcı atamaları (router path'i lowercase'e normalize ettiğinden snake_case kullanılır)
    $router->put('/users/{user_uuid}/role',        [\Api\Controllers\RoleController::class, 'assignToUser'])->name('admin.users.role');
    $router->put('/users/{user_uuid}/permissions', [\Api\Controllers\PermissionController::class, 'setUserOverride'])->name('admin.users.permissions');
});

// ══════════════════════════════════════════
//  MONITOR DASHBOARD (monitor modülü)
//  Salt-okunur gözlemlenebilirlik arayüzü.
//
//  Monitor kapalıysa route'lar HİÇ TANIMLANMAZ: dashboard 404 verir ve
//  varlığını sızdırmaz (kapalı bir izleme arayüzünün 403 dönmesi, saldırgana
//  "burada bir panel var" demektir).
//
//  Erişim zinciri MONITOR_AUTH_MODE'a göre burada kurulur; böylece mevcut
//  api_auth / api_role middleware'leri yeniden kullanılır ve auth mantığı
//  monitor tarafında KOPYALANMAZ. rate_limit her modda ilk sırada: token
//  brute-force'unu yavaşlatır.
// ══════════════════════════════════════════
// MONITOR_ENABLED / MONITOR_AUTH_MODE sabitleri kaldırıldı; ayarlar typed
// config'ten okunur.
//
// SÖZLEŞME: bu dosyayı include eden taraf iki yerel değişken sağlamak
// zorunda — `$router` ve `$monitorConfig`. Sabit okumak yerine değişken
// beklemek kasıtlı: `cache:warm` / `optimize` komutları da bu dosyayı
// include eder ve onların bir HTTP scope'u yoktur.
/** @var \System\Config\MonitorConfig $monitorConfig */
if ($monitorConfig->enabled) {
    // `match` artık string üzerinde değil ENUM üzerinde: geçersiz bir mod
    // temsil edilemez, dolayısıyla config.php'deki eski
    // `in_array($mode, ['rbac','token','both'])` doğrulaması gereksizleşti.
    $monitorMiddleware = match ($monitorConfig->authMode) {
        \System\Config\MonitorAuthMode::TOKEN => ['rate_limit:60,60', 'monitor_token'],
        \System\Config\MonitorAuthMode::BOTH  => ['rate_limit:60,60', 'monitor_token', 'api_auth', 'api_role:administrator'],
        \System\Config\MonitorAuthMode::RBAC  => ['rate_limit:60,60', 'api_auth', 'api_role:administrator'],
    };

    $router->group(['prefix' => '/monitor', 'middleware' => $monitorMiddleware], function ($router) {
        $router->get('/', [\Monitor\Controllers\MonitorController::class, 'index'])->name('monitor.index');
        // Router path'i lowercase'e normalize ettiğinden parametre adı
        // snake_case (admin route'larındaki {user_uuid} ile aynı gerekçe).
        $router->get('/{request_id}', [\Monitor\Controllers\MonitorController::class, 'show'])->name('monitor.show');
    });
}
