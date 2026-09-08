<?php

declare(strict_types=1);

namespace System\Config;

use SensitiveParameter;

/**
 * SMTP ayarları — `SMTP_*` sabitlerinin yerine.
 */
final readonly class MailConfig
{
    public function __construct(
        public string $protocol,
        public string $host,
        public int $port,
        public string $username,
        #[SensitiveParameter] public string $password,
        public string $title,
        /**
         * TLS sertifikası doğrulanacak mı? VARSAYILAN: EVET.
         *
         * ─────────────────────────────────────────────────────────────────
         * NEDEN EKLENDİ — kapatılan bir güvenlik açığı:
         *
         * `MailService::createMailer()` şu bloğu KOŞULSUZ yazıyordu:
         *     'verify_peer' => false, 'verify_peer_name' => false,
         *     'allow_self_signed' => true
         *
         * Yani SMTP bağlantısı sertifika doğrulaması YAPMIYORDU. Araya
         * girebilen biri kendi sertifikasıyla oturur ve parola sıfırlama
         * e-postalarını — dolayısıyla sıfırlama linklerini — okuyabilir.
         * `SMTP_PASSWORD` da aynı bağlantıda gider.
         *
         * Doğrulama artık AÇIK; kapatmak için ortamda açıkça
         * `SMTP_VERIFY_TLS=false` yazmak gerekir. Bu, self-signed
         * sertifikalı yerel bir mail sunucusu için makul; production için
         * DEĞİL — `ConfigValidator` bunu üretimde uyarı olarak bildirir.
         * ─────────────────────────────────────────────────────────────────
         */
        public bool $verifyTls = true,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            protocol: $env->string('SMTP_PROTOCOL', 'tls'),
            host: $env->string('SMTP_HOST'),
            port: $env->int('SMTP_PORT', 587),
            username: $env->string('SMTP_USERNAME'),
            password: $env->string('SMTP_PASSWORD'),
            title: $env->string('SMTP_TITLE'),
            // Varsayılan `true`: ayarı unutmak GÜVENLİ tarafa düşer.
            verifyTls: $env->bool('SMTP_VERIFY_TLS', true),
        );
    }

    /**
     * Mail gönderimi yapılandırılmış mı?
     *
     * Şifre sıfırlama gibi soğuk yollar SMTP'ye bağımlıdır ve yapılandırma
     * eksikse yalnızca o akış çalıştırıldığında patlar. Bunu sorgulanabilir
     * yapmak `app:health` çıktısında görünmesini sağlar.
     */
    public function isConfigured(): bool
    {
        return $this->host !== '';
    }
}
