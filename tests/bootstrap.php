<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN `vendor/autoload.php` DEĞİL DE `config/config.php`:
 *
 * Bu projenin autoload'u HİBRİTTİR. Composer yalnızca `vendor/`'ü yükler;
 * `System\`, `Api\` ve `Monitor\` önekleri `System\Engine\Autoloader`
 * tarafından yüklenir (namespace segmentleri KÜÇÜK HARFE çevrilir, sınıf adı
 * korunur). PSR-4 bu konvansiyonu ifade edemez.
 *
 * `config/config.php` üç şeyi doğru SIRAYLA yapar:
 *   1. PHP sürüm bariyeri
 *   2. `APP_ROOT` + `.env` yüklemesi
 *   3. Composer autoloader'ı, ardından PHPFrame Autoloader'ını kaydeder
 *
 * Sıra önemlidir ve o dosyanın kendi yorumunda gerekçelendirilmiştir: ilk
 * kaydedilen autoloader ilk çalışır, Composer'ın önce gelmesi her vendor
 * sınıfının PHPFrame Autoloader'ında boşa ıskalamasını önler. Aynı sıra
 * `Tests\` PSR-4 önekinin de doğru çözülmesini sağlar — Composer onu
 * yakalar, küçük-harf kuralı hiç devreye girmez.
 *
 * Yalnızca `vendor/autoload.php` require etmek testlerin `System\*`
 * sınıflarını GÖREMEMESİNE yol açardı.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * ── TEST ORTAMI ─────────────────────────────────────────────────────────
 *
 * `.env` OLDUĞU GİBİ okunur; testler için ayrı bir dosya yüklenmez. Gerekçe:
 * `Env::load()` idempotent değildir ve `putenv`/`$_ENV`/`$_SERVER`'a yazar,
 * yani ikinci bir katman eklemek "hangi değer kazandı" sorusunu belirsiz
 * kılardı. Veritabanı/Redis gerektiren testler bağlantıyı KENDİSİ kontrol
 * eder ve erişilemiyorsa `markTestSkipped()` ile atlanır — böylece unit
 * testler altyapı olmadan da koşar.
 */

require_once dirname(__DIR__) . '/config/config.php';

// Testler zaman dilimine bağlı karşılaştırma yapabilir; `RuntimeProvider::boot()`
// container kurulmadan çalışmadığı için burada açıkça set edilir.
date_default_timezone_set(
    (string) (System\Engine\Env::get('APP_TIMEZONE') ?: 'UTC')
);
