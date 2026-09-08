<?php

declare(strict_types=1);

namespace Api\Models;

use Api\Exceptions\DuplicateNameException;
use Api\Exceptions\RecordInUseException;
use PDOException;
use System\Engine\BaseModel;
use System\Helpers\Uuid;

/**
 * Rol veri erişimi: roller, rol↔yetki eşlemesi ve kullanıcı rol ataması.
 *
 * Tek-rol modeli: user_role.user_uuid birincil anahtardır (kullanıcı başına 1 rol).
 */
class RoleModel extends BaseModel
{
    /**
     * Kullanıcının rolünü döner (yoksa null).
     *
     * @return array{uuid: string, name: string, is_superadmin: int}|null
     */
    public function findUserRole(string $userUuid): ?array
    {
        /** @var array{uuid: string, name: string, is_superadmin: int}|null $row */
        $row = $this->selectOne(
            "SELECT r.uuid, r.name, r.is_superadmin
             FROM " . $this->prefix() . "user_role ur
             JOIN " . $this->prefix() . "role r ON r.uuid = ur.role_uuid
             WHERE ur.user_uuid = :u
             LIMIT 1",
            ['u' => $userUuid]
        );

        return $row;
    }

    /**
     * Rolün yetki adlarını döner.
     *
     * @return list<string>
     */
    public function permissionNamesForRole(string $roleUuid): array
    {
        $rows = $this->selectAll(
            "SELECT p.name
             FROM " . $this->prefix() . "role_permission rp
             JOIN " . $this->prefix() . "permission p ON p.uuid = rp.permission_uuid
             WHERE rp.role_uuid = :r",
            ['r' => $roleUuid]
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * Tüm roller (yönetim/listeleme için).
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->selectAll(
            "SELECT uuid, name, is_superadmin, created_at, updated_at
             FROM " . $this->prefix() . "role
             ORDER BY is_superadmin DESC, name ASC"
        );
    }

    /**
     * Ada göre rol bulur.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        return $this->selectOne(
            "SELECT uuid, name, is_superadmin FROM " . $this->prefix() . "role WHERE name = :n LIMIT 1",
            ['n' => $name]
        );
    }

    /**
     * uuid'e göre rol bulur (yönetim uçlarında 404 ayrımı için).
     *
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            "SELECT uuid, name, is_superadmin FROM " . $this->prefix() . "role WHERE uuid = :u LIMIT 1",
            ['u' => $uuid]
        );
    }

    /**
     * Kullanıcıya rol atar/değiştirir (tek-rol). Atomik upsert: user_uuid PK
     * olduğundan tek INSERT...ON DUPLICATE KEY UPDATE yarış penceresi bırakmaz.
     * (PostgreSQL'de karşılığı: ON CONFLICT (user_uuid) DO UPDATE SET role_uuid = EXCLUDED.role_uuid.)
     */
    public function assignUserRole(string $userUuid, string $roleUuid): bool
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "user_role (user_uuid, role_uuid)
             VALUES (:u, :r)
             ON DUPLICATE KEY UPDATE role_uuid = VALUES(role_uuid)"
        );

        try {
            return $stmt->execute(['u' => $userUuid, 'r' => $roleUuid]);
        } catch (PDOException $e) {
            // YALNIZCA FK ihlali yutulur ("böyle bir kullanıcı yok" —
            // istemci hatası). Eskiden her `PDOException` `false`'a düşüyordu,
            // yani bağlantı kopması da "geçersiz kullanıcı" gibi görünüyor ve
            // loglardan siliniyordu.
            if ($this->isForeignKeyViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Yeni rol oluşturur; oluşan uuid'i döner.
     *
     * is_superadmin DAİMA 0 — API'den superadmin verilemez (privilege
     * escalation koruması). Bu, SQL literalinde tutulan bir güvenlik
     * kuralıdır; parametreye çevrilmemelidir.
     *
     * @throws DuplicateNameException ad zaten kullanımdaysa
     */
    public function createRole(string $name): string
    {
        $uuid = Uuid::generate();
        $stmt = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "role (uuid, name, is_superadmin) VALUES (:uuid, :name, 0)"
        );

        try {
            $stmt->execute(['uuid' => $uuid, 'name' => $name]);
            return $uuid;
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new DuplicateNameException($name, previous: $e);
            }
            throw $e;
        }
    }

    /**
     * Rolü yeniden adlandırır.
     *
     * Dönüş: satır GERÇEKTEN değişti mi. Aynı adla çağrılırsa MySQL 0 satır
     * bildirir ve `false` döner — bu bir HATA DEĞİLDİR, istenen son durum
     * zaten sağlanmıştır. Çağıran (`RoleService::rename`) bu ayrımı bilir.
     *
     * @throws DuplicateNameException yeni ad başka bir rolde kullanımdaysa
     */
    public function renameRole(string $uuid, string $name): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE " . $this->prefix() . "role SET name = :name, updated_at = NOW() WHERE uuid = :uuid"
        );

        try {
            $stmt->execute(['name' => $name, 'uuid' => $uuid]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new DuplicateNameException($name, previous: $e);
            }
            throw $e;
        }
    }

    /**
     * Rolü siler. Dönüş: böyle bir rol var mıydı.
     *
     * `role_permission` CASCADE ile temizlenir; `user_role` FK'si RESTRICT
     * olduğundan kullanımdaki rol silinemez.
     *
     * @throws RecordInUseException rol bir kullanıcıya atanmışsa
     */
    public function deleteRole(string $uuid): bool
    {
        $stmt = $this->pdo()->prepare("DELETE FROM " . $this->prefix() . "role WHERE uuid = :uuid");

        try {
            $stmt->execute(['uuid' => $uuid]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Eskiden tespit `($e->errorInfo[0] ?? '') === '23000'` idi.
            // MySQL'de 23000 SQLSTATE'i FK ihlalinin YANI SIRA unique
            // ihlalini de kapsar; yani bir benzersizlik çakışması "rol
            // kullanımda" (409) diye raporlanabilirdi. Artık sürücü koduna
            // kadar iniliyor (BaseModel::isForeignKeyViolation).
            if ($this->isForeignKeyViolation($e)) {
                throw new RecordInUseException($uuid, $e);
            }

            throw $e;
        }
    }

    /**
     * Rolün TÜM yetki eşlemelerini siler.
     *
     * `syncPermissions()`'ın yerini aldı. Eski metot transaction'ı KENDİ
     * açıyordu ve üç sorunu vardı:
     *   • `beginTransaction()` `try` bloğunun DIŞINDAYDI,
     *   • yalnızca `PDOException` yakalanıyordu — bir `Throwable` (örn.
     *     dizide string olmayan bir uuid) transaction'ı AÇIK bırakırdı ve PDO
     *     bağlantısı memoize edildiği için aynı istekteki sonraki HER sorgu o
     *     yetim transaction'a katılırdı,
     *   • yetki başına ayrı INSERT (N+1).
     *
     * Transaction sahipliği artık `RoleService`'te (`Database::transaction()`).
     */
    public function clearPermissions(string $roleUuid): void
    {
        $stmt = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "role_permission WHERE role_uuid = :r"
        );
        $stmt->execute(['r' => $roleUuid]);
    }

    /**
     * Role yetki eşlemeleri ekler — TEK çok-satırlı INSERT.
     *
     * @param list<string> $permissionUuids
     * @throws RecordInUseException geçersiz rol/yetki uuid'i (FK ihlali)
     */
    public function addPermissions(string $roleUuid, array $permissionUuids): void
    {
        $unique = array_values(array_unique($permissionUuids));

        if ($unique === []) {
            return;
        }

        // `(:r0, :p0), (:r1, :p1), ...` — eski N+1 döngüsünün yerine tek gidiş.
        //
        // ROL UUID'İ HER SATIRDA AYRI PLACEHOLDER ALIR. Tek bir `:r`'yi
        // tekrar kullanmak doğal görünür ama `ATTR_EMULATE_PREPARES = false`
        // ile (bkz. DatabaseConfig::pdoOptions) çalışmaz: native prepare'de
        // aynı isimli parametre bir ifadede YALNIZCA BİR KEZ geçebilir.
        // Emülasyon açık olsaydı sessizce çalışırdı — yani bu, sürücü
        // ayarına bağlı olarak ortaya çıkan bir hata sınıfıdır.
        $placeholders = [];
        $params       = [];

        foreach ($unique as $i => $permUuid) {
            $placeholders[]  = "(:r{$i}, :p{$i})";
            $params["r{$i}"] = $roleUuid;
            $params["p{$i}"] = $permUuid;
        }

        $stmt = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "role_permission (role_uuid, permission_uuid) VALUES "
            . implode(', ', $placeholders)
        );

        try {
            $stmt->execute($params);
        } catch (PDOException $e) {
            if ($this->isForeignKeyViolation($e)) {
                throw new RecordInUseException($roleUuid, $e);
            }

            throw $e;
        }
    }

    // NOT: `isUniqueViolation()` buradan KALDIRILDI — artık `BaseModel`'de.
    // Aynı SQLSTATE kontrolü RoleModel, PermissionModel ve UserModel içinde
    // üç kopya hâlinde duruyordu; sürücü davranışı bilgisi persistence
    // tabanına aittir. `BaseModel::isForeignKeyViolation()` de oradadır.
}
