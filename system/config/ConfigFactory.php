<?php

declare(strict_types=1);

namespace System\Config;

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
    private ?MediaConfig $media = null;

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

    public function media(): MediaConfig
    {
        return $this->media ??= MediaConfig::fromEnv($this->env, $this->app());
    }

    /**
     * @param bool $validate
     * @return array<class-string, object>
     */
    public function all(bool $validate = true): array
    {
        $app = $this->app();

        if ($validate) {
            $this->validator->assert($app, $this->database(), $this->auth(), $this->monitor(), $this->http(), $this->media());
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
            MediaConfig::class     => $this->media(),
        ];
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->validator->problems(
            $this->app(),
            $this->database(),
            $this->auth(),
            $this->monitor(),
            $this->http(),
            $this->media(),
        );
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return [
            ...$this->validator->warnings(
                $this->app(),
                $this->database(),
                $this->auth(),
                $this->monitor(),
                $this->cache(),
                $this->mail(),
                $this->env->string('PHPFRAME_TEST_PASSWORD') !== '',
                $this->media(),
            ),
            ...$this->validator->envFileWarnings($this->app(), $this->env->shadowed()),
        ];
    }

    public function env(): EnvReader
    {
        return $this->env;
    }
}
