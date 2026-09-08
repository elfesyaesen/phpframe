<?php

declare(strict_types=1);

namespace System\Config;

use PDO;
use SensitiveParameter;
use System\Database\DatabaseDriver;

/**
 * Veritabanı bağlantı ayarları.
 *
 * `DB_*` sabitlerinin ve `DB_OPTIONS` dizisinin yerini alır. `Database`
 * artık global sabit okumaz; bu objeyi enjekte alır.
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public DatabaseDriver $driver,
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        #[SensitiveParameter] public string $password,
        public string $charset,
        public string $prefix,
        public bool $persistent,
        public int $timeout,
        public ?MysqlTlsConfig $tls = null,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        $driver = DatabaseDriver::tryFrom(strtolower($env->string('DB_DRIVER', 'mysql')))
            ?? DatabaseDriver::MYSQL;

        // TLS objesi YALNIZCA sürücü MySQL ve DB_SSL açıkken kurulur; böylece
        // PDO::MYSQL_ATTR_* sabitlerine başka hiçbir durumda dokunulmaz
        // (mysql sürücüsü yoksa o sabitler tanımsızdır).
        $tls = $driver === DatabaseDriver::MYSQL && $env->bool('DB_SSL')
            ? MysqlTlsConfig::fromEnv($env)
            : null;

        return new self(
            driver: $driver,
            host: $env->string('DB_HOST', '127.0.0.1'),
            port: $env->int('DB_PORT', $driver->defaultPort()),
            name: $env->string('DB_NAME'),
            user: $env->string('DB_USER', 'root'),
            password: $env->string('DB_PASS'),
            charset: $env->string('DB_CHARSET', $driver->defaultCharset()),
            prefix: $env->string('DB_PREFIX'),
            // Kalıcı bağlantı istek başına TCP/TLS el sıkışmasını eler.
            // PgBouncer ile birlikte AÇILMAMALI.
            persistent: $env->bool('DB_PERSISTENT'),
            // Bağlantı timeout'u: erişilemez DB'de istek asılı kalmasın.
            timeout: $env->int('DB_TIMEOUT', 5),
            tls: $tls,
        );
    }

    public function dsn(): string
    {
        return $this->driver->buildDsn($this->host, $this->name, $this->port, $this->charset);
    }

    /**
     * PDO seçenekleri — eski `DB_OPTIONS` sabitinin karşılığı.
     *
     * @return array<int, mixed>
     */
    public function pdoOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => $this->persistent,
            PDO::ATTR_TIMEOUT            => $this->timeout,
        ];

        return $this->tls === null
            ? $options
            : $options + $this->tls->pdoOptions();
    }

    /** Tablo adına prefix uygular. */
    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * Log/hata bağlamı — ŞİFRE ASLA DAHİL EDİLMEZ.
     *
     * @return array<string, mixed>
     */
    public function toSafeContext(): array
    {
        return [
            'driver' => $this->driver->value,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->name,
            'user' => $this->user,
            'persistent' => $this->persistent,
            'tls' => $this->tls !== null,
        ];
    }
}
