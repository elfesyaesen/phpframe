<?php

declare(strict_types=1);

/**
 * HTTP front controller.
 *
 * İSTEK SCOPE'U burada açılır ve kapanır. Bu, göçün en önemli davranış
 * değişikliğidir: `Request`, `Response`, `Recorder`, `RequestContext`,
 * `LocaleResolver`, `Translator` ve `Gate` artık SCOPED — her istek kendi
 * örneklerini alır.
 *
 * Neden gerekliydi: PHP-FPM'de bir worker ardışık birçok isteği aynı
 * process'te karşılar, dolayısıyla singleton = worker ömrü boyunca tek örnek.
 * Mutable auth durumu taşıyan `Request` singleton olduğunda, bir worker'ın
 * gördüğü 2. istek 1. isteğin kullanıcısını görürdü. Dev'de worker tek istek
 * gördüğü için bu ASLA görünmez; production'da kullanıcı verisi karışır.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * SCOPE KAPATMA — artık `finally` GERÇEKTEN çalışıyor.
 *
 * Bu not eskiden şöyleydi: *"`Response::jsonResponse()` ve
 * `BaseController::view()` `exit` çağırdığı için `finally` her zaman
 * çalışmaz"*. Bu doğruydu ve scope teardown fiilen shutdown hook'una
 * bırakılmıştı — `finally` çoğu istekte hiç çalışmıyordu.
 *
 * `exit` kaldırıldığı için (bkz. `Response` sınıf docblock'u) normal akışta
 * `finally` çalışır. Shutdown hook (`ShutdownPriority::SCOPE_DISPOSE`) hâlâ
 * KALIR ve gereklidir: fatal error durumunda `ExceptionHandler::handleShutdown()`
 * `exit(1)` yapar ve `finally`'ye hiç ulaşılmaz. `dispose()` idempotenttir,
 * iki yoldan çağrılması sorun değil.
 * ─────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../bootstrap.php';

$scope = $kernel->beginRequestScope();

// routes/routes.php iki yerel değişken bekler: `$router` ve `$monitorConfig`
// (bkz. o dosyanın başındaki sözleşme notu).
$router = $scope->get(System\Routing\Router::class);
$monitorConfig = $scope->get(System\Config\MonitorConfig::class);

require APP_ROOT . '/routes/routes.php';

try {
    $router->dispatch($scope->get(System\Http\Request::class));
} finally {
    $scope->dispose();
}
