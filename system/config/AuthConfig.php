<?php

declare(strict_types=1);

namespace System\Config;

use SensitiveParameter;

/**
 * Kimlik doğrulama / JWT ayarları.
 *
 * `SECRET_KEY` ve token ömürleri sabit olmaktan çıkar. Bu, plan §30'un
 * güvenlik gerekçesinin merkezinde: config objeleri container'a INSTANCE
 * olarak girer, yani derlenmiş PHP dosyasına ASLA yazılmaz.
 */
final readonly class AuthConfig
{
    public function __construct(
        #[SensitiveParameter] public string $secretKey,
        public int $accessTokenExpire,
        public int $refreshTokenExpire,
        /**
         * Parola sıfırlama token'ının ömrü — SANİYE. Varsayılan 30 dakika.
         *
         * Access token'dan (1 saat) kısa tutulur: sıfırlama linki e-posta
         * kutusunda bekleyen, tek kullanımlık ve tam hesap devralma yetkisi
         * taşıyan bir sırdır. Pencere ne kadar kısaysa, kutuya sonradan
         * erişen birinin işine yaraması o kadar zorlaşır.
         */
        public int $passwordResetExpire = 1800,
        /**
         * Sıfırlama linkinin işaret ettiği ÖN YÜZ hedefi.
         *
         * API ucu DEĞİL: token'ı kullanıcıdan yeni parolayı alacak sayfaya
         * (ya da uygulamaya) taşır, o da `POST /users/reset-password/confirm`
         * ucunu çağırır.
         *
         * İki biçim kabul edilir ve `resetLink()` hangisi olduğuna karar
         * verir:
         *   • GÖRELİ yol  → `APP_URL` ile birleştirilir ("reset-password")
         *   • MUTLAK URL  → aynen kullanılır. Ön yüz ayrı bir origin'de
         *     olduğunda ("https://app.example.com/reset") ya da universal
         *     link / custom scheme kullanıldığında ("myapp://reset") gerekli.
         */
        public string $passwordResetUrl = 'reset-password',
        /**
         * OTP kodu için hatalı deneme bütçesi.
         *
         * Kod 6 hane, yani 10^6 olasılık — hedefli bir saldırgan için
         * erişilebilir bir sayı. Rota bazlı rate limit yetmez, IP döndürerek
         * aşılır; bu yüzden sayaç token'ın KENDİSİNDE tutulur ve bu sayıya
         * ulaşan deneme kaydı siler.
         *
         * 5, kullanılabilirlikle güvenlik arasındaki makul nokta: elle kod
         * girerken bir-iki hata olağan, beş hata değil. Bütçe bitince
         * kullanıcı yeniden talep eder — bedeli bir e-posta.
         */
        public int $passwordResetMaxAttempts = 5,
    ) {}

    /**
     * Verilen token için tam sıfırlama bağlantısını kurar.
     *
     * `passwordResetUrl` mutlaksa (bir şema içeriyorsa) `APP_URL` ön eki
     * EKLENMEZ. Bu ayrım olmadan "myapp://reset" değeri
     * "http://localhost/myapp://reset" haline gelirdi — sessizce bozuk bir
     * link. Mobil-only kurulumlarda universal link ya da custom scheme
     * kullanılabilmesi buna bağlı.
     */
    public function resetLink(AppConfig $app, string $token): string
    {
        $query = (str_contains($this->passwordResetUrl, '?') ? '&' : '?') . 'token=' . $token;

        // `parse_url` yerine desen: `parse_url` "myapp://x" gibi bilinmeyen
        // şemaları da çözer ama "reset-password" için de `path` döndürür;
        // ayrımı yapan şey şemanın VARLIĞI, o da tek bir desenle ölçülür.
        $isAbsolute = preg_match('#^[a-z][a-z0-9+.\-]*://#i', $this->passwordResetUrl) === 1;

        return $isAbsolute
            ? $this->passwordResetUrl . $query
            : $app->url($this->passwordResetUrl . $query);
    }

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            secretKey: $env->string('SECRET_KEY'),
            accessTokenExpire: $env->int('ACCESS_TOKEN_EXPIRE', 3600),
            refreshTokenExpire: $env->int('REFRESH_TOKEN_EXPIRE', 604800),
            passwordResetExpire: $env->int('PASSWORD_RESET_EXPIRE', 1800),
            passwordResetUrl: $env->string('PASSWORD_RESET_URL', 'reset-password'),
            passwordResetMaxAttempts: $env->int('PASSWORD_RESET_MAX_ATTEMPTS', 5),
        );
    }

    public function hasStrongSecret(): bool
    {
        return strlen($this->secretKey) >= 32;
    }
}
