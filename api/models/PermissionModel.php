<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Exceptions\DuplicateNameException;
use PDOException;
use System\Engine\BaseModel;
use System\Helpers\Uuid;

/**
 * Yetki veri erişimi: yetki listesi ve kullanıcıya özel (+/−) override'lar.
 */
class PermissionModel extends BaseModel
{
    /**
     * `user_permission.effect` sütununun kabul ettiği değerler.
     *
     * Sabit olarak tutulmalarının sebebi savunma sırası: string literal olarak
     * hem `userOverrides()` (okuma) hem `setUserOverride()` (yazma) içinde
     * geçiyorlardı. İkisi ayrışırsa — örneğin biri 'DENY' yazarsa — yazılan
     * deny okunurken "deny değil" sayılıp GRANT'a dönüşürdü.
     */
    public const EFFECT_GRANT = 'grant';
    public const EFFECT_DENY  = 'deny';

    /**
     * Kullanıcının grant(+)/deny(−) override yetki adlarını döner.
     *
     * @return array{grant: list<string>, deny: list<string>}
     */
    public function userOverrides(string $userUuid): array
    {
        $rows = $this->selectAll(
            "SELECT p.name, up.effect
             FROM " . $this->prefix() . "user_permission up
             JOIN " . $this->prefix() . "permission p ON p.uuid = up.permission_uuid
             WHERE up.user_uuid = :u",
            ['u' => $userUuid]
        );

        $grant = [];
        $deny  = [];
        foreach ($rows as $row) {
            // "deny değilse grant" — kasıtlı: tanınmayan bir değer yetki
            // VERMEK yerine reddedilmeli... ancak mevcut davranış tersidir ve
            // korunuyor (bkz. setUserOverride'daki yazma-tarafı doğrulaması,
            // geçersiz değerin sütuna hiç girmemesini sağlar).
            if (($row['effect'] ?? null) === self::EFFECT_DENY) {
                $deny[] = (string) $row['name'];
            } else {
                $grant[] = (string) $row['name'];
            }
        }

        return ['grant' => $grant, 'deny' => $deny];
    }

    /**
     * Tüm yetkiler (yönetim/listeleme için).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->selectAll(
            "SELECT uuid, name, description, created_at, updated_at
             FROM " . $this->prefix() . "permission
             ORDER BY name ASC"
        );
    }

    /**
     * Ada göre yetki bulur.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        return $this->selectOne(
            "SELECT uuid, name, description FROM " . $this->prefix() . "permission WHERE name = :n LIMIT 1",
            ['n' => $name]
        );
    }

    /**
     * Yeni yetki oluşturur; oluşan uuid'i döner.
     *
     * @throws DuplicateNameException ad zaten kullanımdaysa
     *
     * Eskiden `string|array{error:string}` dönüyordu ve çağıran
     * `is_array($result)` ile hata ayırt ediyordu. Union-dönüş sözleşmesi
     * kolay unutulur: kontrolü atlayan bir çağıran hata dizisini uuid sanıp
     * sessizce "başarı" raporlardı. Artık başarısızlık TİP sistemiyle
     * bildiriliyor.
     */
    public function createPermission(string $name, ?string $description): string
    {
        $uuid = Uuid::generate();
        $stmt = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "permission (uuid, name, description) VALUES (:uuid, :name, :description)"
        );

        try {
            $stmt->execute(['uuid' => $uuid, 'name' => $name, 'description' => $description]);
            return $uuid;
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new DuplicateNameException($name, previous: $e);
            }

            // Unique dışındaki hatalar YUTULMAZ: bağlantı kopması, kolon
            // uyuşmazlığı vb. gerçek arızalardır ve yukarı çıkmalıdır.
            throw $e;
        }
    }

    /**
     * Yetkiyi siler. role_permission ve user_permission FK'leri CASCADE olduğundan
     * ilişkili eşlemeler/override'lar otomatik temizlenir.
     */
    public function deletePermission(string $uuid): bool
    {
        $stmt = $this->pdo()->prepare("DELETE FROM " . $this->prefix() . "permission WHERE uuid = :uuid");

        try {
            $stmt->execute(['uuid' => $uuid]);
            return $stmt->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * Kullanıcıya özel yetki override'ı ayarlar.
     * - $effect null  → override kaldırılır (DELETE)
     * - 'grant'/'deny' → upsert (atomik; user_uuid+permission_uuid PK)
     */
    public function setUserOverride(string $userUuid, string $permissionUuid, ?string $effect): bool
    {
        // Sütun ENUM('grant','deny') — geçersiz bir değer MySQL'de strict mode
        // kapalıysa sessizce boş stringe dönüşür ve `userOverrides()` onu
        // "deny değil" diye GRANT sayardı, yani yetki VERİRDİ. HTTP katmanı
        // zaten `in:grant,deny` ile doğruluyor; bu, ikinci savunma hattıdır.
        if ($effect !== null && $effect !== self::EFFECT_GRANT && $effect !== self::EFFECT_DENY) {
            return false;
        }

        try {
            if ($effect === null) {
                $stmt = $this->pdo()->prepare(
                    "DELETE FROM " . $this->prefix() . "user_permission
                     WHERE user_uuid = :u AND permission_uuid = :p"
                );
                $stmt->execute(['u' => $userUuid, 'p' => $permissionUuid]);

                // Kaldırma İDEMPOTENTTİR: override zaten yoksa da istenen son
                // durum sağlanmıştır. rowCount()'a bakmak "zaten kaldırılmış"
                // durumunu hata gibi gösterirdi.
                return true;
            }

            $stmt = $this->pdo()->prepare(
                "INSERT INTO " . $this->prefix() . "user_permission (user_uuid, permission_uuid, effect)
                 VALUES (:u, :p, :e)
                 ON DUPLICATE KEY UPDATE effect = VALUES(effect)"
            );

            return $stmt->execute(['u' => $userUuid, 'p' => $permissionUuid, 'e' => $effect]);
        } catch (PDOException $e) {
            // YALNIZCA FK ihlali yutulur: "böyle bir kullanıcı yok" istemci
            // hatasıdır ve servis onu 422'ye çevirir. Bağlantı kopması gibi
            // gerçek arızalar yukarı çıkar — eskiden hepsi aynı `catch` ile
            // `false`'a düşüyor ve loglardan siliniyordu.
            if ($this->isForeignKeyViolation($e)) {
                return false;
            }

            throw $e;
        }
    }
}
