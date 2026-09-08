<?php

declare(strict_types=1);

/**
 * Bootstrap primitive'leri.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * BU DOSYA ESKİDEN ~60 `define()` İÇERİYORDU. HEPSİ SİLİNDİ.
 *
 * Yapılandırma artık typed config objelerinde yaşıyor (`system/config/*`) ve
 * container'a INSTANCE olarak giriyor (DI-plan §25):
 *
 *   Environment → Configuration → Typed Config → DI
 *
 * Neden global sabitler yeterli değildi:
 *   • Sabit okuyan bir sınıfın bağımlılığı imzasında GÖRÜNMÜYOR, dolayısıyla
 *     derleyici onu doğrulayamıyor ve test edilemiyor.
 *   • `defined('X') ? X : 'varsayılan'` kalıbı, "config yüklendi mi"
 *     sorusunu her kullanım yerinde yeniden soruyordu; cevap bağlama göre
 *     değişebiliyordu (güvenlik ayarlarında kabul edilemez — bkz. eski
 *     `Request::isTrustedProxy`).
 *   • Sabitler derlenmiş container'a gömülemezdi; config'in dışarıdan
 *     verilmesi sayesinde DB_PASS/SECRET_KEY üretilen PHP dosyasına ASLA
 *     yazılmaz (DI-plan §30).
 *
 * `APP_ROOT` İSTİSNADIR ve KALIR: o bir config değeri değil, Autoloader'ın
 * herhangi bir obje var olmadan önce ihtiyaç duyduğu bir bootstrap
 * primitive'idir.
 *
 * Zaman dilimi ayarı → `RuntimeProvider::boot()`
 * Production env doğrulaması → `System\Config\ConfigValidator`
 * ─────────────────────────────────────────────────────────────────────────
 */

// PHPFrame minimum PHP sürüm bariyeri (DI-plan hedefi: PHP 8.5).
// Hem web (bootstrap.php) hem CLI (frame) bu dosyayı ilk yükler.
if (PHP_VERSION_ID < 80500) {
    $message = sprintf(
        'PHPFrame PHP 8.5 veya üzerini gerektirir. Mevcut sürüm: %s',
        PHP_VERSION
    );

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }

    exit($message . PHP_EOL);
}

// Uygulama kökü önce TİPLİ bir yerele alınır, sonra sabite yazılır.
//
// Gerekçe: `define()` ile tanımlanan sabitlerin tipi statik analiz
// araçlarınca `mixed` olarak çıkarılır. `Env::load(string $path)` gibi
// tipli bir imzaya `APP_ROOT` geçmek, `declare(strict_types=1)` altında
// "Expected string, found mixed" uyarısı üretir — çalışma zamanında sorun
// yoktur (`dirname()` string döndürür) ama uyarı gerçek bir belirsizliği
// işaret eder: sabitin tipi imzada görünmez.
//
// Yerel değişken hem uyarıyı ortadan kaldırır hem aşağıdaki iki
// `require_once` yolunun sabiti tekrar okumasını gereksiz kılar.
$appRoot = dirname(__DIR__);

define('APP_ROOT', $appRoot);

require_once $appRoot . '/system/engine/Env.php';
System\Engine\Env::load($appRoot);

// Composer autoloader — TÜM üçüncü parti kod buradan gelir.
//
// ─────────────────────────────────────────────────────────────────────────
// TEK KANAL: `vendor/`
//
// Bu proje eskiden üçüncü parti kütüphaneleri `system/library/` altına ELLE
// kopyalıyor ve namespace'lerini `System\Library\*` altına taşıyordu
// ("shading"). O yaklaşım terk edildi. Neden — iki somut olay:
//
//   1. DÜŞÜRÜLMÜŞ BAĞIMLILIK. Elle kopyalanan Twig, `trigger_deprecation()`
//      fonksiyonunu 183 yerden çağırıyordu; fonksiyon Twig'in kendisinde değil
//      `symfony/deprecation-contracts` paketindeydi ve kopyalama sırasında
//      atlanmıştı. Bir deprecated Twig yoluna girmek "Call to undefined
//      function" FATAL'i veriyordu. Bir bağımlılık AĞACINI elle çözmek
//      ölçeklenmez.
//
//   2. GÖRÜNMEYEN GÜVENLİK AÇIKLARI. Elle vendor edilen sürümler
//      twig/twig 3.22.2 ve firebase/php-jwt 6.10.1'di. Composer'ın advisory
//      veritabanı ikisini de kurmayı REDDETTİ: Twig 16, php-jwt 1 advisory
//      taşıyordu ve php-jwt'nin 6.x hattının TAMAMI etkilenmişti. Elle
//      vendoring bunu görünmez kılmıştı; "etkileniyor muyuz?" sorusunun
//      cevabı yoktu. `composer audit` o cevabı verir.
//
// Kural: üçüncü parti hiçbir kod elle kopyalanmaz. `composer require`.
//
// FIRST-PARTY BURAYA GİRMEZ (System\, Api\, Monitor\): bu proje namespace
// segmentlerini KÜÇÜK HARF dizinlere eşler (System\Container\Core ->
// system/container/core/) ve PSR-4 bunu İFADE EDEMEZ — PSR-4 §3 dizin adının
// segmentle harf büyüklüğü dahil birebir eşleşmesini şart koşar. Windows'ta
// (case-insensitive FS) kazara çalışır, Linux'ta kırılırdı. Bu yüzden
// first-party yükleme System\Engine\Autoloader'da KALIR.
//
// NEDEN AUTOLOADER'DAN ÖNCE: ilk kaydedilen autoloader ilk çalışır. Composer
// önce gelince vendor sınıfları tek `is_file()` ile çözülür; sonra gelseydi
// her vendor sınıfı önce PHPFrame'in Autoloader'ında ıskalayıp fazladan bir
// stat harcardı. Ters yönde kayıp yok: Composer'ın loader'ı `System\` önekini
// haritasında bulamayıp dosya sistemine hiç dokunmadan geçer.
//
// GUARD YOK — ve bu kasıtlıdır. Üçüncü parti kodun tamamı artık `vendor/`
// altında olduğu için, o dizin olmadan uygulama zaten çalışamaz. `is_file()`
// ile sessizce geçmek, hatayı boot'tan alıp ilk Twig render'ına kadar
// erteler ve "class not found" gibi ilgisiz bir yere düşürür. Eksik
// `vendor/` GÜRÜLTÜLÜ ve ERKEN patlamalıdır.
// ─────────────────────────────────────────────────────────────────────────
require_once $appRoot . '/vendor/autoload.php';

require_once $appRoot . '/system/engine/Autoloader.php';
new System\Engine\Autoloader();

// Boot kapsamını temiz tut: bu dosyayı include eden taraf (bootstrap.php,
// frame) yalnızca kendi değişkenlerini görsün.
unset($appRoot);
