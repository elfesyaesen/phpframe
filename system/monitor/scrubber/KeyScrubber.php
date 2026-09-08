<?php

declare(strict_types=1);

namespace System\Monitor\Scrubber;

use System\Monitor\Contracts\ScrubberInterface;

/**
 * Anahtar-adı tabanlı hassas veri maskeleyici.
 *
 * Bir anahtarın adı hassas listesindeki parçalardan birini İÇERİYORSA (substring
 * eşleşme) değeri maskelenir. Substring eşleşmesi kasıtlıdır: `password`,
 * `password_confirmation`, `old_password`, `user_password` tek kuralla kapanır.
 *
 * Bu sınıf `ExceptionHandler`'ın kendi private maskeleme mantığının yerine
 * geçer — tek bir maskeleme kaynağı olsun diye. Varsayılan anahtar listesi ve
 * maske metni oradaki davranışla BİREBİR aynıdır, böylece log çıktısı değişmez.
 */
final class KeyScrubber implements ScrubberInterface
{
    /**
     * Varsayılan hassas anahtar parçaları.
     *
     * İlk 10 giriş `ExceptionHandler::SENSITIVE_KEYS` ile aynıdır (davranış
     * korunumu). Kalanlar monitor'e özgüdür: monitor `ExceptionHandler`'ın
     * aksine ham istek/yanıt gövdesini saklar, dolayısıyla ödeme ve oturum
     * alanlarını da görebilir.
     */
    public const DEFAULT_KEYS = [
        'password', 'passwd', 'secret', 'token', 'authorization',
        'auth', 'api_key', 'apikey', 'credential', 'cookie',
        // Monitor'e özgü genişletme
        'pwd', 'pass', 'refresh_token', 'access_token', 'client_secret',
        'private_key', 'signature', 'otp', 'pin', 'cvv', 'cvc',
        'card', 'pan', 'iban', 'session',
    ];

    public const DEFAULT_MASK = '***FILTERED***';

    /**
     * @param array<int, string> $sensitiveKeys
     */
    public function __construct(
        private readonly array $sensitiveKeys = self::DEFAULT_KEYS,
        private readonly string $mask = self::DEFAULT_MASK,
    ) {}

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function scrub(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                // Authorization/Proxy-Authorization için şemayı koru: "Bearer ***"
                // biçimi, hangi kimlik doğrulama yönteminin kullanıldığını teşhis
                // için görünür bırakır ama sırrı sızdırmaz.
                $result[$key] = is_string($value)
                    ? $this->maskPreservingScheme($value)
                    : $this->mask;

                continue;
            }

            $result[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $result;
    }

    public function scrubText(string $body, int $maxBytes): string
    {
        if ($body === '') {
            return '';
        }

        $structured = $this->decodeStructured($body);

        if ($structured !== null) {
            $encoded = json_encode(
                $this->scrub($structured),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($encoded !== false) {
                return $this->truncate($encoded, $maxBytes);
            }
        }

        // Yapılandırılmış olarak çözülemedi (binary, HTML, düz metin): anahtar
        // bazlı maskeleme uygulanamaz. İçerik korunur, yalnızca kırpılır.
        return $this->truncate($body, $maxBytes);
    }

    /**
     * Gövdeyi JSON veya form-urlencoded olarak diziye çevirmeyi dener.
     *
     * @return array<mixed>|null
     */
    private function decodeStructured(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Form-urlencoded sezgisi: JSON değil ama 'a=b' kalıbı var. `parse_str`
        // her girdiyi kabul eder, o yüzden önce kaba bir kontrol gerekir; aksi
        // halde düz metin bir gövde ("hata oluştu") tek anahtarlı diziye dönüşür.
        if (!str_contains($body, '=') || str_contains($body, "\n")) {
            return null;
        }

        parse_str($body, $parsed);

        return $parsed === [] ? null : $parsed;
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);

        foreach ($this->sensitiveKeys as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskPreservingScheme(string $value): string
    {
        foreach (['Bearer', 'Basic', 'Digest'] as $scheme) {
            if (stripos($value, $scheme . ' ') === 0) {
                return $scheme . ' ' . $this->mask;
            }
        }

        return $this->mask;
    }

    private function truncate(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . "\n…[truncated]";
    }
}
