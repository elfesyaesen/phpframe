<?php

declare(strict_types=1);

namespace System\Config;

use RuntimeException;
use System\Http\TrustedProxies;

final class ConfigValidator
{
    /**
     * @return list<string>
     */
    public function problems(
        AppConfig $app,
        DatabaseConfig $database,
        AuthConfig $auth,
        MonitorConfig $monitor,
        ?HttpConfig $http = null,
        ?MediaConfig $media = null,
    ): array {
        $problems = [];

        if ($media !== null && !$media->hasStrongSigningKey()) {
            $problems[] = 'MEDIA_SIGNING_KEY (en az 32 karakter)';
        }

        if ($http !== null && in_array('*', $http->corsOrigins, true)) {
            $problems[] = "CORS_ORIGINS (production'da `*` olamaz — izin verilen origin'leri virgülle listeleyin)";
        }

        $invalidOrigins = $http?->invalidOrigins() ?? [];
        if ($invalidOrigins !== []) {
            $problems[] = 'CORS_ORIGINS (geçersiz origin: ' . implode(', ', $invalidOrigins)
                . ' — şema + host, ör. https://app.example.com)';
        }

        $invalidProxies = $http !== null ? TrustedProxies::invalidEntries($http->trustedProxies) : [];
        if ($invalidProxies !== []) {
            $problems[] = 'TRUSTED_PROXIES (geçersiz girdi: ' . implode(', ', $invalidProxies)
                . ' — yalnızca IP veya CIDR, ör. 10.0.0.0/8, fd00::/8)';
        }

        if (!$auth->hasStrongSecret()) {
            $problems[] = 'SECRET_KEY (en az 32 karakter)';
        }

        if ($database->name === '') {
            $problems[] = 'DB_NAME';
        }

        if ($database->user === '') {
            $problems[] = 'DB_USER';
        }

        if ($monitor->enabled && strlen($monitor->token) < 32) {
            $problems[] = 'MONITOR_TOKEN (MONITOR_ENABLED=true iken en az 32 karakter)';
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    public function warnings(
        AppConfig $app,
        DatabaseConfig $database,
        AuthConfig $auth,
        MonitorConfig $monitor,
        CacheConfig $cache,
        ?MailConfig $mail = null,
        bool $testCredentialsPresent = false,
        ?MediaConfig $media = null,
    ): array {
        $warnings = [];

        if ($app->production) {
            $warnings = [...$warnings, ...$this->productionWarnings(
                $app, $database, $auth, $monitor, $cache, $mail, $testCredentialsPresent,
            )];
        }

        if ($media !== null) {
            $warnings = [...$warnings, ...$this->mediaWarnings($app, $auth, $media)];
        }

        if ($monitor->token !== '' && strlen($monitor->token) < 32) {
            $warnings[] = sprintf(
                'MONITOR_TOKEN 32 karakterden kısa (%d). Dashboard istek/yanıt '
                . 'gövdelerini bu sırla açar; production\'da boot durur.',
                strlen($monitor->token),
            );
        }

        if ($monitor->enabled && $monitor->token === '') {
            $warnings[] = 'MONITOR_ENABLED=true ama MONITOR_TOKEN boş — kayıt '
                . 'tutuluyor, ancak dashboard her istekte 404 döner. Üretmek için: '
                . 'php -r "echo bin2hex(random_bytes(32));"';
        }

        if ($monitor->enabled && $monitor->recordQueries && $database->persistent) {
            $warnings[] = 'MONITOR_RECORD_QUERIES=true ama DB_PERSISTENT=true — '
                . 'sorgu kaydı DEVRE DIŞI. PDO kalıcı bağlantılarda '
                . 'ATTR_STATEMENT_CLASS desteklemez; izleme uğruna veritabanı '
                . 'erişimini kaybetmemek için sessizce atlanır (query_count her '
                . 'istekte 0 görünür).';
        }

        if ($app->production && $monitor->enabled && $monitor->captureResponseBody) {
            $warnings[] = 'MONITOR_CAPTURE_RESPONSE_BODY production\'da açık — '
                . 'her istek için ob_start() ve yanıt gövdesi bellekte tutulur. '
                . 'Büyük dosya indirmelerinde bellek maliyeti oluşur.';
        }

        if ($app->production && !$cache->usesRedis()) {
            $warnings[] = sprintf(
                'CACHE_DRIVER=%s (redis değil) — cache-first katmanı devre dışı, '
                . 'veritabanı yükü artar.',
                $cache->driver,
            );
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function productionWarnings(
        AppConfig $app,
        DatabaseConfig $database,
        AuthConfig $auth,
        MonitorConfig $monitor,
        CacheConfig $cache,
        ?MailConfig $mail,
        bool $testCredentialsPresent,
    ): array {
        $warnings = [];

        if (!str_starts_with(strtolower($app->url), 'https://')) {
            $warnings[] = sprintf(
                'APP_URL HTTPS değil (%s) — parola sıfırlama linkleri token\'ı şifresiz '
                . 'taşır ve HSTS gönderilmez.',
                $app->url === '' ? 'boş' : $app->url,
            );
        }

        if ($mail !== null) {
            if (!$mail->verifyTls) {
                $warnings[] = 'SMTP_VERIFY_TLS=false — SMTP sertifikası doğrulanmıyor; araya giren '
                    . 'biri sıfırlama e-postalarını ve SMTP_PASSWORD\'ü okuyabilir.';
            }

            if ($mail->host === '' || preg_match('/(^|\.)(example\.(com|org|net)|localhost|test|invalid)$/i', $mail->host) === 1) {
                $warnings[] = sprintf(
                    'SMTP_HOST yer tutucu görünüyor (%s) — parola sıfırlama e-postaları '
                    . 'gönderilemez ve hata sessizdir (yalnızca log).',
                    $mail->host === '' ? 'boş' : $mail->host,
                );
            }
        }

        if ($auth->accessTokenExpire > 3600) {
            $warnings[] = sprintf(
                'ACCESS_TOKEN_EXPIRE=%d — çalınan access token bu süre boyunca iptal '
                . 'edilemez. Önerilen: 900 (15 dk).',
                $auth->accessTokenExpire,
            );
        }

        if ($monitor->enabled && $monitor->captureRequestBody) {
            $warnings[] = 'MONITOR_CAPTURE_REQUEST_BODY production\'da açık — istek gövdeleri '
                . '(maskelense de) kişisel veri olarak MONITOR_RETENTION_DAYS boyunca saklanır.';
        }

        if (!self::isLocalHost($database->host) && $database->tls === null) {
            $warnings[] = sprintf(
                'Veritabanı uzak sunucuda (%s) ama DB_SSL kapalı — sorgular ve DB_PASS '
                . 'ağda şifresiz gider.',
                $database->host,
            );
        }

        if ($cache->usesRedis() && !self::isLocalHost($cache->redis->host) && !$cache->redis->hasPassword()) {
            $warnings[] = sprintf(
                'Redis uzak sunucuda (%s) ve REDIS_PASSWORD boş — cache ve rate-limit '
                . 'sayaçları kimlik doğrulamasız.',
                $cache->redis->host,
            );
        }

        if ($testCredentialsPresent) {
            $warnings[] = 'PHPFRAME_TEST_PASSWORD production .env\'inde tanımlı — test hesabının '
                . 'kimlik bilgileri sunucuda duruyor. Satırı ve hesabı kaldırın.';
        }

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function mediaWarnings(AppConfig $app, AuthConfig $auth, MediaConfig $media): array
    {
        $warnings = [];

        if (!$media->hasStrongSigningKey()) {
            $warnings[] = 'MEDIA_SIGNING_KEY 32 karakterden kısa — özel medya adresleri '
                . 'üretilemez; production\'da boot durur. Üretmek için: '
                . 'php -r "echo bin2hex(random_bytes(32));"';
        } elseif (hash_equals($media->signingKey, $auth->secretKey)) {
            $warnings[] = 'MEDIA_SIGNING_KEY, SECRET_KEY ile aynı — biri sızarsa ikisi '
                . 'birden gider. Ayrı bir anahtar üretin.';
        }

        if ($media->avif && !function_exists('imageavif') && !extension_loaded('imagick')) {
            $warnings[] = 'MEDIA_AVIF=true ama kurulu sürücü AVIF üretemiyor — yalnızca WebP üretilir.';
        }

        if ($app->production && !str_starts_with(strtolower($media->publicBaseUrl), 'https://')) {
            $warnings[] = sprintf(
                'MEDIA_PUBLIC_BASE_URL HTTPS değil (%s) — HTTPS sayfalarda görseller '
                . 'karışık içerik olarak engellenir.',
                $media->publicBaseUrl,
            );
        }

        return $warnings;
    }

    /**
     * @param list<string> $shadowed
     * @return list<string>
     */
    public function envFileWarnings(AppConfig $app, array $shadowed = []): array
    {
        $warnings = [];
        $root     = rtrim($app->root, '/\\');

        foreach (['public/.env', 'public/.env.example', 'public/.env.bak'] as $relative) {
            if (is_file($root . '/' . $relative)) {
                $warnings[] = sprintf(
                    '%s web kökünde duruyor — web sunucusu onu düz metin olarak sunabilir. '
                    . 'Dosyayı public/ dışına taşıyın.',
                    $relative,
                );
            }
        }

        $envFile = $root . '/.env';
        if (DIRECTORY_SEPARATOR === '/' && is_file($envFile)) {
            $perms = fileperms($envFile);
            if ($perms !== false && ($perms & 0o004) !== 0) {
                $warnings[] = sprintf(
                    '.env herkes tarafından okunabilir (izin %o) — sırlar sunucudaki diğer '
                    . 'kullanıcılara açık. Önerilen: chmod 640 .env (sahip: uygulama kullanıcısı).',
                    $perms & 0o777,
                );
            }
        }

        if ($shadowed !== []) {
            $warnings[] = sprintf(
                '.env içindeki şu anahtarlar gerçek environment tarafından geçersiz kılındı '
                . '(web sunucusu, konteyner ya da shell): %s — .env\'deki değerleri kullanılmıyor.',
                implode(', ', $shadowed),
            );
        }

        return $warnings;
    }

    private static function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['127.0.0.1', '::1', 'localhost', ''], true)
            || str_starts_with($host, '/');
    }

    public function assert(
        AppConfig $app,
        DatabaseConfig $database,
        AuthConfig $auth,
        MonitorConfig $monitor,
        ?HttpConfig $http = null,
        ?MediaConfig $media = null,
    ): void {
        if (!$app->production) {
            return;
        }

        $problems = $this->problems($app, $database, $auth, $monitor, $http, $media);

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(
            'PHPFrame yapılandırma hatası — eksik/geçersiz .env anahtarları: '
            . implode(', ', $problems)
        );
    }
}
