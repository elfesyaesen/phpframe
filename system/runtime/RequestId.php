<?php

declare(strict_types=1);

namespace System\Runtime;

/**
 * İstek korelasyon kimliği — SCOPED.
 *
 * Eskiden bootstrap.php'de bir yerel değişkendi (`$requestId`) ve iki closure
 * tarafından `use ($requestId)` ile yakalanıyordu. Yakalanan yerel değişken
 * derlenemez: closure'ın kapattığı durum var_export edilemez. Bu yüzden
 * birinci sınıf bir scoped servis oldu.
 *
 * SCOPED olmak zorunda: her istek kendi kimliğine sahip. Singleton olsaydı
 * bir PHP-FPM worker'ının gördüğü tüm istekler aynı korelasyon kimliğini
 * paylaşır ve log ↔ monitor eşleştirmesi sessizce yalan söylerdi.
 */
final readonly class RequestId
{
    private function __construct(
        public string $value,
        public bool $fromUpstream,
    ) {}

    /**
     * Upstream (proxy/LB) bir kimlik gönderdiyse onurlandırır, yoksa üretir.
     *
     * Upstream değeri DOĞRULANIR: header istemci kontrolündedir ve doğrudan
     * log satırlarına ve monitor kayıtlarının birincil anahtarına yazılır.
     * Desen dışı bir değer (kontrol karakteri, aşırı uzunluk) log enjeksiyonu
     * veya depolama hatası üretebilir.
     *
     * @param array<string, mixed> $server $_SERVER
     */
    public static function fromServer(array $server): self
    {
        $incoming = $server['HTTP_X_REQUEST_ID'] ?? '';

        if (is_string($incoming) && preg_match('/^[A-Za-z0-9\-]{1,64}$/', $incoming) === 1) {
            return new self($incoming, true);
        }

        return self::generate();
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)), false);
    }

    /** CLI için sabit önekli kimlik — log satırları ayırt edilebilsin. */
    public static function forCli(): self
    {
        return new self('cli-' . bin2hex(random_bytes(8)), false);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
