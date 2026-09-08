<?php

declare(strict_types=1);

namespace Api\Models;

use System\Engine\BaseModel;
use System\Helpers\Uuid;

/**
 * Parola sıfırlama token'ları — saf veri erişimi.
 *
 * Her kayıt İKİ anahtar taşır ve ikisi de yalnızca SHA-256 hash'i olarak
 * saklanır, düz metin ASLA yazılmaz:
 *
 *   • `token_hash` → e-postadaki LİNK (64 karakter hex, 256 bit CSPRNG)
 *   • `code_hash`  → e-postadaki OTP KODU (6 hane)
 *
 * Gerekçeler ve şema kararları:
 *   migration/2026_09_08_000001_create_password_reset.php
 *   migration/2026_09_08_000002_add_otp_to_password_reset.php
 */
class PasswordResetModel extends BaseModel
{
    /**
     * Yeni sıfırlama kaydı yazar — link ve kod aynı satırda.
     *
     * @param string $tokenHash SHA-256 hex (64 karakter)
     * @param string $codeHash  SHA-256 hex (64 karakter)
     * @param int    $expiresAt Unix timestamp
     */
    public function store(string $userUuid, string $tokenHash, string $codeHash, int $expiresAt): void
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "password_reset
             (uuid, user_uuid, token_hash, code_hash, expires_at)
             VALUES (:uuid, :user_uuid, :token_hash, :code_hash, FROM_UNIXTIME(:expires_at))"
        );

        $stmt->execute([
            'uuid'       => Uuid::generate(),
            'user_uuid'  => $userUuid,
            'token_hash' => $tokenHash,
            'code_hash'  => $codeHash,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * LİNK yolu: token hash'ine karşılık gelen süresi geçmemiş kaydı bulur.
     *
     * Süre kontrolü SQL'de yapılır, PHP'de değil: karşılaştırma veritabanı
     * saatine göre olur ve uygulama sunucusuyla DB arasındaki saat kayması
     * bir güvenlik farkı yaratmaz.
     *
     * Burada `attempts` OKUNMAZ ve önemsizdir — 256 bit token'ı doğru bilen
     * biri kaba kuvvet denemiyor demektir. Sayaç aşımı zaten kaydı SİLER,
     * yani bu sorgu hiç sonuç döndürmez.
     *
     * @return array{uuid: string, user_uuid: string}|null
     */
    public function findValid(string $tokenHash): ?array
    {
        /** @var array{uuid: string, user_uuid: string}|null $row */
        $row = $this->selectOne(
            "SELECT uuid, user_uuid
             FROM " . $this->prefix() . "password_reset
             WHERE token_hash = :hash AND expires_at > NOW()
             LIMIT 1",
            ['hash' => $tokenHash]
        );

        return $row;
    }

    /**
     * OTP yolu: kullanıcının süresi geçmemiş kaydını bulur.
     *
     * Kod'a göre DEĞİL kullanıcıya göre aranır — bu bilinçli. Yalnızca koda
     * bakan bir sorgu (`WHERE code_hash = ?`) tüm kullanıcılar arasında
     * eşleşme arardı: saldırgan tek bir kodu deneyerek O KODU BEKLEYEN
     * HERHANGİ bir hesabı ele geçirebilirdi. Kullanıcı sayısı arttıkça
     * başarı olasılığı da artar (doğum günü paradoksu). Kullanıcıyı önce
     * sabitlemek arama uzayını tek hesaba indirir.
     *
     * `code_hash` ve `attempts` DÖNDÜRÜLÜR, karşılaştırma SQL'de YAPILMAZ:
     * eşitlik kontrolü PHP'de `hash_equals` ile sabit zamanda yapılır ve
     * hatalı deneme sayaca yazılır. SQL'de karşılaştırılsaydı "kayıt yok"
     * ile "kod yanlış" ayrımı kaybolur, sayaç hiç artmazdı.
     *
     * @return array{uuid: string, code_hash: string, attempts: int}|null
     */
    public function findValidForUser(string $userUuid): ?array
    {
        /** @var array{uuid: string, code_hash: string, attempts: string|int}|null $row */
        $row = $this->selectOne(
            "SELECT uuid, code_hash, attempts
             FROM " . $this->prefix() . "password_reset
             WHERE user_uuid = :user_uuid AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1",
            ['user_uuid' => $userUuid]
        );

        if ($row === null) {
            return null;
        }

        return [
            'uuid'      => (string) $row['uuid'],
            'code_hash' => (string) $row['code_hash'],
            'attempts'  => (int) $row['attempts'],
        ];
    }

    /**
     * Hatalı denemeyi sayar; sayaç aşıldıysa kaydı siler.
     *
     * ─────────────────────────────────────────────────────────────────────
     * Artırma SQL'de `attempts + 1` ile yapılır, PHP'de okunan değer üzerine
     * yazılmaz. Sebep bir yarış durumu: iki eşzamanlı yanlış deneme aynı
     * `attempts` değerini okuyup ikisi de aynı sayıyı yazarsa bir deneme
     * SAYILMAZ. Saldırgan istekleri paralelleştirerek bütçesini büyütür.
     * `attempts + 1` bunu veritabanına bırakır.
     *
     * Silme AYNI ifadenin ardından ve TRANSACTION DIŞINDA çağrılmalıdır:
     * bu metot hatalı denemeden sonra çalışır ve ardından bir exception
     * fırlatılır. Transaction içinde olsaydı rollback sayacı da geri alır,
     * yani kaba kuvvet denemesi hiç kaydedilmezdi — korumanın tamamı
     * kaybolurdu.
     * ─────────────────────────────────────────────────────────────────────
     *
     * @param int $max Bu sayıya ulaşan deneme kaydı öldürür
     *
     * @return bool kayıt silindiyse `true`
     */
    public function registerFailedAttempt(string $uuid, int $max): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE " . $this->prefix() . "password_reset
             SET attempts = attempts + 1
             WHERE uuid = :uuid"
        );
        $stmt->execute(['uuid' => $uuid]);

        $delete = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "password_reset
             WHERE uuid = :uuid AND attempts >= :max"
        );
        $delete->execute(['uuid' => $uuid, 'max' => $max]);

        return $delete->rowCount() > 0;
    }

    /**
     * Kullanıcının TÜM sıfırlama kayıtlarını siler.
     *
     * İki yerde çağrılır ve ikisi de kasıtlı:
     *   • Yeni talep öncesi — böylece kullanıcı başına tek geçerli link/kod
     *     olur ve eski e-postalardaki bağlantılar ölür.
     *   • Tüketim sonrası — kayıt tek kullanımlıktır.
     *
     * `used_at` işareti YERİNE silme: kullanılmış bir sırrın hash'ini
     * saklamak temizlenmesi gereken kalıcı veri bırakır, karşılığında
     * hiçbir şey kazandırmaz.
     */
    public function deleteForUser(string $userUuid): void
    {
        $stmt = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "password_reset WHERE user_uuid = :user_uuid"
        );
        $stmt->execute(['user_uuid' => $userUuid]);
    }

    /*
     * Süresi geçmiş kayıtların toplu temizliği BURADA DEĞİL.
     *
     * `expires_at` indeksi o iş için var ama sorguyu `auth:purge` komutu
     * kendi içinde yazıyor: komut `System\` katmanında ve katman sözleşmesi
     * (docs/api-layer.md) `System\`'in `Api\`'yi bilmesini yasaklıyor.
     * Burada bir `purgeExpired()` bırakmak, çağıranı olmayan ölü kod olurdu.
     */
}
