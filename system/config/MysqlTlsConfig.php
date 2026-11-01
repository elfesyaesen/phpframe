<?php

declare(strict_types=1);

namespace System\Config;

use PDO;

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

        $hasClientCertificate = $certificate !== '' && $key !== '';

        return new self(
            ca: $ca !== '' ? $ca : null,
            certificate: $hasClientCertificate ? $certificate : null,
            key: $hasClientCertificate ? $key : null,
            verifyServerCertificate: $env->bool('DB_SSL_VERIFY', true),
        );
    }

    /**
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
