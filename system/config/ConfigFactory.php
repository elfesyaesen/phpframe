<?php

declare(strict_types=1);

namespace System\Config;

/**
 * Typed config objelerini kurar (DI-plan §25).
 *
 *   Environment → Configuration → Typed Config → DI
 *
 * NEDEN CONTAINER'DAN ÖNCE: config objeleri grafiğin GİRDİSİdir, ürünü değil.
 * Kernel onları container kurulmadan önce üretir ve `instance()` ile verir.
 * İki sonucu var:
 *   1. Hiçbir servis config'i "yanlış zamanda" çözemez — hazır olmadan
 *      container yoktur.
 *   2. Derleyici bir sırrı `var/cache`'e `var_export` etmek zorunda kalmaz —
 *      config INSTANCE lifetime'ıyla external olur, yani DB_PASS/SECRET_KEY
 *      üretilen PHP dosyasına ASLA yazılmaz (DI-plan §30).
 *
 * Objeler bir kez kurulup memoize edilir: aynı `AppConfig` örneği hem
 * `MonitorConfig::fromEnv()`'e girer hem container'a verilir, dolayısıyla
 * `production` gibi bir değer iki farklı yerde farklı okunamaz.
 */
final class ConfigFactory
{
    private ?AppConfig $app = null;
    private ?DatabaseConfig $database = null;
    private ?MonitorConfig $monitor = null;
    private ?CacheConfig $cache = null;
    private ?LogConfig $log = null;
    private ?AuthConfig $auth = null;
    private ?HttpConfig $http = null;
    private ?MailConfig $mail = null;
    private ?UpstreamConfig $upstream = null;
    private ?RateLimitConfig $rateLimit = null;

    public function __construct(
        private readonly EnvReader $env,
        private readonly string $appRoot,
        private readonly ConfigValidator $validator = new ConfigValidator(),
    ) {}

    public static function forRoot(string $appRoot): self
    {
        return new self(new EnvReader(), $appRoot);
    }

    public function app(): AppConfig
    {
        return $this->app ??= new AppConfig(
            root: $this->appRoot,
            url: $this->env->string('APP_URL', 'http://localhost/'),
            // Varsayılan TRUE: yanlış yapılandırmada güvenli tarafta kal.
            // Yanlışlıkla production'da debug açmak, dev'de kapalı olmasından
            // çok daha pahalıdır (yığın izi ve sorgu detayı sızdırır).
            production: $this->env->bool('APP_PRODUCTION', true),
            timezone: $this->env->string('APP_TIMEZONE', 'Europe/Istanbul'),
        );
    }

    public function database(): DatabaseConfig
    {
        return $this->database ??= DatabaseConfig::fromEnv($this->env);
    }

    public function monitor(): MonitorConfig
    {
        return $this->monitor ??= MonitorConfig::fromEnv($this->env, $this->app());
    }

    public function cache(): CacheConfig
    {
        return $this->cache ??= CacheConfig::fromEnv($this->env);
    }

    public function redis(): RedisConfig
    {
        return $this->cache()->redis;
    }

    public function log(): LogConfig
    {
        return $this->log ??= LogConfig::fromEnv($this->env, $this->app());
    }

    public function auth(): AuthConfig
    {
        return $this->auth ??= AuthConfig::fromEnv($this->env);
    }

    public function http(): HttpConfig
    {
        return $this->http ??= HttpConfig::fromEnv($this->env);
    }

    public function mail(): MailConfig
    {
        return $this->mail ??= MailConfig::fromEnv($this->env);
    }

    public function upstream(): UpstreamConfig
    {
        return $this->upstream ??= UpstreamConfig::fromEnv($this->env);
    }

    public function rateLimit(): RateLimitConfig
    {
        return $this->rateLimit ??= RateLimitConfig::fromEnv($this->env);
    }

    /**
     * Tüm config objeleri, container'a `instance()` ile verilmeye hazır.
     *
     * Production'da doğrulama burada koşar: eksik/zayıf anahtarlar container
     * kurulmadan, tek bir yerde yakalanır.
     *
     * @param bool $validate false ise doğrulama atlanır (CLI raporlama için)
     * @return array<class-string, object>
     */
    public function all(bool $validate = true): array
    {
        $app = $this->app();

        if ($validate) {
            $this->validator->assert($app, $this->database(), $this->auth(), $this->monitor());
        }

        return [
            AppConfig::class       => $app,
            DatabaseConfig::class  => $this->database(),
            MonitorConfig::class   => $this->monitor(),
            CacheConfig::class     => $this->cache(),
            RedisConfig::class     => $this->redis(),
            LogConfig::class       => $this->log(),
            AuthConfig::class      => $this->auth(),
            HttpConfig::class      => $this->http(),
            MailConfig::class      => $this->mail(),
            UpstreamConfig::class  => $this->upstream(),
            RateLimitConfig::class => $this->rateLimit(),
        ];
    }

    /**
     * Production'da boot'u durduran yapılandırma problemleri (fırlatmadan).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->validator->problems(
            $this->app(),
            $this->database(),
            $this->auth(),
            $this->monitor(),
        );
    }

    /**
     * Ölümcül olmayan yapılandırma uyarıları — `app:health` gösterir,
     * boot'u durdurmaz (bkz. ConfigValidator::warnings docblock'u).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->validator->warnings(
            $this->app(),
            $this->database(),
            $this->auth(),
            $this->monitor(),
            $this->cache(),
        );
    }

    public function env(): EnvReader
    {
        return $this->env;
    }
}
