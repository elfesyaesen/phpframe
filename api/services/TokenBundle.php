<?php

declare(strict_types=1);

namespace Api\Services;

/**
 * Bir oturum açma/yenileme sonucunda üretilen token çifti.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN DTO — kod tabanındaki 22 CRUD ucu ham dizi kullanmaya devam ederken
 * bu ikisi (ve `AvatarUpload`) neden tipli:
 *
 * `AuthService::token(array $users)` her şeyi kabul ediyordu ve çağıranlar
 * FARKLI ŞEKİLLER geçiyordu:
 *   • `login()`    → `authenticate()`'in satırı (7 sütun, password silinmiş)
 *   • `refresh()`  → `getUserByUuid()`'nin satırı (8 sütun)
 *   • `register()` → yine `getUserByUuid()`
 *
 * Üçü de aynı JWT `user` claim'ine gömülüyordu, yani `access_token.user`
 * frontend için KARARLI BİR SÖZLEŞME DEĞİLDİ — hangi uçtan geldiğine göre
 * alan kümesi değişiyordu. Tipleme bu ayrışmayı kapatır.
 * ─────────────────────────────────────────────────────────────────────────
 */
final readonly class TokenBundle
{
    /**
     * @param array<string, mixed> $user JWT'ye gömülen kullanıcı gösterimi
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $expiredAt,
        public array $user,
    ) {
    }

    /**
     * HTTP yanıt gövdesi.
     *
     * Anahtar adları KORUNUYOR (`access_token`, `refresh_token`,
     * `expired_at`, `user`) — mevcut istemciler bu şekle göre yazılmış
     * durumda ve bu iş yanıt sözleşmesini değiştirmeyi hedeflemiyor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expired_at'    => $this->expiredAt,
            'user'          => $this->user,
        ];
    }
}
