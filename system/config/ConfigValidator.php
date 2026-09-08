<?php

declare(strict_types=1);

namespace System\Config;

use RuntimeException;

/**
 * Production boot-time config doğrulaması.
 *
 * `config.php`'nin sonundaki `$configErrors` bloğunun yerine geçer ve aynı
 * fail-fast davranışı korur. Ayrı bir sınıf olmasının kazancı: artık CLI'dan
 * da çağrılabilir (`frame config:audit`), yani "production'da patlar mı?"
 * sorusu deploy'dan ÖNCE yanıtlanabilir.
 *
 * Doğrulama yalnızca production'da zorlayıcıdır: dev'de atılabilir anahtarla
 * çalışmak meşru.
 */
final class ConfigValidator
{
    /**
     * @return list<string> Eksik/geçersiz ayarların insan-okunur listesi
     */
    public function problems(
        AppConfig $app,
        DatabaseConfig $database,
        AuthConfig $auth,
        MonitorConfig $monitor,
    ): array {
        $problems = [];

        // Boş SECRET_KEY → JWT boş anahtarla imzalanır. Sessiz güvenlik hatası.
        if (!$auth->hasStrongSecret()) {
            $problems[] = 'SECRET_KEY (en az 32 karakter)';
        }

        if ($database->name === '') {
            $problems[] = 'DB_NAME';
        }

        if ($database->user === '') {
            $problems[] = 'DB_USER';
        }

        // Monitor dashboard istek/yanıt gövdelerini gösterir; token ile
        // açılıyorsa zayıf bir sır DOĞRUDAN veri sızıntısıdır.
        if ($monitor->enabled
            && $monitor->authMode->usesToken()
            && strlen($monitor->token) < 32
        ) {
            $problems[] = 'MONITOR_TOKEN (en az 32 karakter — MONITOR_AUTH_MODE='
                . $monitor->authMode->value . ')';
        }

        return $problems;
    }

    /**
     * Ölümcül OLMAYAN yapılandırma uyarıları.
     *
     * ─────────────────────────────────────────────────────────────────────
     * NEDEN AYRI BİR YÜZEY:
     *
     * `problems()` boot'u durdurur — orada yalnızca "bu hâliyle çalışamaz"
     * durumları olabilir. Ama bir de "şu an zararsız, ama bir ayar
     * değişirse tehlikeli" sınıfı var ve bunlar sessiz kalırsa tam olarak o
     * ayar değiştiğinde fark edilir:
     *
     *   • MONITOR_TOKEN zayıf ama mod `rbac` → bugün devrede değil, ama
     *     `token`/`both`'a geçiş anında dashboard istek/yanıt gövdelerini
     *     zayıf bir sırla açar. Yani risk, ayarı değiştiren kişinin
     *     token'ın gücünü hatırlamasına bağlı kalır.
     *   • DB_PERSISTENT + MONITOR_RECORD_QUERIES → sorgu kaydı SESSİZCE
     *     kapalı kalır (PDO kalıcı bağlantılarda ATTR_STATEMENT_CLASS'ı
     *     desteklemez). "Neden query_count her yerde 0?" sorusunun cevabı.
     *
     * `app:health` bunları uyarı olarak gösterir; çıkış kodunu etkilemezler.
     * ─────────────────────────────────────────────────────────────────────
     *
     * @return list<string>
     */
    public function warnings(
        AppConfig $app,
        DatabaseConfig $database,
        AuthConfig $auth,
        MonitorConfig $monitor,
        CacheConfig $cache,
    ): array {
        $warnings = [];

        // Monitor token'ı, MODU NE OLURSA OLSUN denetlenir.
        if ($monitor->token !== '' && strlen($monitor->token) < 32) {
            $warnings[] = sprintf(
                'MONITOR_TOKEN 32 karakterden kısa (%d). Şu an MONITOR_AUTH_MODE=%s '
                . 'olduğu için devrede değil, ancak token/both moduna geçilirse '
                . 'dashboard istek/yanıt gövdelerini zayıf bir sırla açar.',
                strlen($monitor->token),
                $monitor->authMode->value,
            );
        }

        // Sorgu kaydı sessizce devre dışı mı?
        if ($monitor->enabled && $monitor->recordQueries && $database->persistent) {
            $warnings[] = 'MONITOR_RECORD_QUERIES=true ama DB_PERSISTENT=true — '
                . 'sorgu kaydı DEVRE DIŞI. PDO kalıcı bağlantılarda '
                . 'ATTR_STATEMENT_CLASS desteklemez; izleme uğruna veritabanı '
                . 'erişimini kaybetmemek için sessizce atlanır (query_count her '
                . 'istekte 0 görünür).';
        }

        // Yanıt gövdesi yakalama production'da bellek maliyeti demek.
        if ($app->production && $monitor->enabled && $monitor->captureResponseBody) {
            $warnings[] = 'MONITOR_CAPTURE_RESPONSE_BODY production\'da açık — '
                . 'her istek için ob_start() ve yanıt gövdesi bellekte tutulur. '
                . 'Büyük dosya indirmelerinde bellek maliyeti oluşur.';
        }

        // Cache-first mimari Redis'e dayanıyor; kapalıysa DB yükü artar.
        if ($app->production && !$cache->usesRedis()) {
            $warnings[] = sprintf(
                'CACHE_DRIVER=%s (redis değil) — cache-first katmanı devre dışı, '
                . 'veritabanı yükü artar.',
                $cache->driver,
            );
        }

        // Örnekleme %100 production'da her isteği kaydeder.
        if ($app->production && $monitor->enabled && $monitor->sampleRate >= 100) {
            $warnings[] = 'MONITOR_SAMPLE_RATE=100 production\'da — HER istek '
                . 'kaydedilir. Depolama ve yazma yükü trafikle doğrusal büyür.';
        }

        return $warnings;
    }

    /**
     * Production'da problem varsa istisna fırlatır.
     *
     * Eski davranış `exit()` idi; istisna fırlatmak daha iyi çünkü CLI
     * doğrulama komutu aynı kontrolü çalıştırıp raporlayabilir. Kernel bunu
     * yakalayıp aynı fail-fast çıktısını üretir.
     */
    public function assert(
        AppConfig $app,
        DatabaseConfig $database,
        AuthConfig $auth,
        MonitorConfig $monitor,
    ): void {
        if (!$app->production) {
            return;
        }

        $problems = $this->problems($app, $database, $auth, $monitor);

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(
            'PHPFrame yapılandırma hatası — eksik/geçersiz .env anahtarları: '
            . implode(', ', $problems)
        );
    }
}
