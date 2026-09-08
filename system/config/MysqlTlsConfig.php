<?php

declare(strict_types=1);

namespace System\Config;

use PDO;

/**
 * MySQL TLS seçenekleri.
 *
 * NEDEN AYRI BİR SINIF: `config.php`'de bu ayarlar bir `if (DB_DRIVER === 'mysql'
 * && DB_SSL)` dalı içinde kuruluyordu ve `PDO::MYSQL_ATTR_*` sabitlerine
 * yalnızca o dalda dokunulması gerekiyordu (mysql sürücüsü yüklü değilse
 * sabitler TANIMSIZ olur ve fatal verir). Yani doğruluk, İFADE SIRASINA
 * bağlıydı.
 *
 * Bu sınıf o kısıtı tip sistemine taşır: `DatabaseConfig::$tls` yalnızca
 * sürücü MySQL ve TLS açıkken null-olmayan olabilir, dolayısıyla
 * `MYSQL_ATTR_*` sabitlerine yalnızca o durumda dokunulur — bir `if`
 * unutulduğu için değil, obje var olmadığı için.
 */
final readonly class MysqlTlsConfig
{
    public function __construct(
        public ?string $ca = null,
        public ?string $certificate = null,
        public ?string $key = null,
        public bool $verifyServerCertificate = true,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        $ca = $env->string('DB_SSL_CA');
        $certificate = $env->string('DB_SSL_CERT');
        $key = $env->string('DB_SSL_KEY');

        // Sertifika ve anahtar YALNIZCA İKİSİ BİRLİKTE verildiğinde etkin —
        // config.php'deki davranışın birebir korunması. Tek başına sertifika
        // PDO tarafından sessizce yok sayılır ve "TLS açık sandım" durumu üretir.
        $hasClientCertificate = $certificate !== '' && $key !== '';

        return new self(
            ca: $ca !== '' ? $ca : null,
            certificate: $hasClientCertificate ? $certificate : null,
            key: $hasClientCertificate ? $key : null,
            verifyServerCertificate: $env->bool('DB_SSL_VERIFY', true),
        );
    }

    /**
     * PDO sürücü seçenekleri.
     *
     * TLS ayarları DSN string'i ile DEĞİL, sürücü seçenekleriyle verilir.
     *
     * @return array<int, mixed>
     */
    public function pdoOptions(): array
    {
        $options = [];

        if ($this->ca !== null) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $this->ca;
        }

        if ($this->certificate !== null && $this->key !== null) {
            $options[PDO::MYSQL_ATTR_SSL_CERT] = $this->certificate;
            $options[PDO::MYSQL_ATTR_SSL_KEY] = $this->key;
        }

        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $this->verifyServerCertificate;

        return $options;
    }
}
