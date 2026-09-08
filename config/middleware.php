<?php

declare(strict_types=1);

/**
 * Middleware alias → sınıf eşlemesi.
 *
 * Eskiden bu eşleme `System\Middleware\Alias` içinde bir STATIC dizideydi ve
 * `Alias::register()` ile runtime'da değiştirilebiliyordu. Buraya taşınması
 * üç şey kazandırır:
 *
 *   1. Derleme zamanında GÖRÜNÜR: `container:validate` her hedef sınıfın
 *      çözülebilir bir MiddlewareInterface olduğunu doğrular. Yanlış yazılmış
 *      bir sınıf adı artık build hatası — eskiden o korumalı route'a ilk
 *      istek geldiğinde ortaya çıkıyordu, yani route sessizce korumasız
 *      kalabiliyordu.
 *   2. Runtime mutasyonu imkânsız (DI-plan §24).
 *   3. Dosya içeriği derleme hash'ine girer (DI-plan §31): alias eklemek
 *      derlemeyi bayatlatır ve `--check` kapısı bunu yakalar.
 *
 * Kullanım route tanımlarında: `'api_auth'` veya parametreli
 * `'rate_limit:100,120'` (100 istek / 120 saniye).
 */

return [
    // ── Kimlik doğrulama ve yetkilendirme ──
    'api_auth'       => \Api\Middleware\AuthMiddleware::class,
    'api_role'       => \Api\Middleware\RoleMiddleware::class,
    'api_permission' => \Api\Middleware\PermissionMiddleware::class,

    // ── Hız sınırlama ──
    // Parametresiz kullanımda varsayılanlar RateLimitConfig'ten gelir
    // (eskiden middleware sınıfındaki DEFAULT_* sabitleriydi).
    'rate_limit'     => \System\Middleware\RateLimitMiddleware::class,

    // ── Monitor dashboard ──
    // MONITOR_AUTH_MODE=token|both iken devreye girer.
    'monitor_token'  => \Monitor\Middleware\MonitorTokenMiddleware::class,
];
