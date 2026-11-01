<?php

declare(strict_types=1);

namespace App\Api\Models;

use App\Api\Exceptions\DuplicateNameException;
use App\Api\Exceptions\RecordInUseException;
use PDOException;
use System\Engine\BaseModel;
use System\Helpers\Uuid;

class RoleModel extends BaseModel
{
    /**
     * @return array{uuid: string, name: string, superadmin: int}|null
     */
    public function findUserRole(string $userUuid): ?array
    {
        /** @var array{uuid: string, name: string, superadmin: int}|null $row */
        $row = $this->selectOne(
            "SELECT r.uuid, r.name, r.superadmin
             FROM " . $this->prefix() . "user_role ur
             JOIN " . $this->prefix() . "role r ON r.uuid = ur.role_uuid
             WHERE ur.user_uuid = :u
             LIMIT 1",
            ['u' => $userUuid]
        );

        return $row;
    }

    /**
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
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->selectAll(
            "SELECT uuid, name, superadmin, created_at, updated_at
             FROM " . $this->prefix() . "role
             ORDER BY superadmin DESC, name ASC"
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        return $this->selectOne(
            "SELECT uuid, name, superadmin FROM " . $this->prefix() . "role WHERE name = :n LIMIT 1",
            ['n' => $name]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        return $this->selectOne(
            "SELECT uuid, name, superadmin FROM " . $this->prefix() . "role WHERE uuid = :u LIMIT 1",
            ['u' => $uuid]
        );
    }

    public function countSuperadminUsers(): int
    {
        $rows = $this->selectAll(
            "SELECT ur.user_uuid
             FROM " . $this->prefix() . "user_role ur
             JOIN " . $this->prefix() . "role r ON r.uuid = ur.role_uuid
             WHERE r.superadmin = 1
             FOR UPDATE",
        );

        return count($rows);
    }

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
            if ($this->isForeignKeyViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * @throws DuplicateNameException
     */
    public function createRole(string $name): string
    {
        $uuid = Uuid::generate();
        $stmt = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "role (uuid, name, superadmin) VALUES (:uuid, :name, 0)"
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
     * @throws DuplicateNameException
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
     * @throws RecordInUseException
     */
    public function deleteRole(string $uuid): bool
    {
        $stmt = $this->pdo()->prepare("DELETE FROM " . $this->prefix() . "role WHERE uuid = :uuid");

        try {
            $stmt->execute(['uuid' => $uuid]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            if ($this->isForeignKeyViolation($e)) {
                throw new RecordInUseException($uuid, $e);
            }

            throw $e;
        }
    }

    public function clearPermissions(string $roleUuid): void
    {
        $stmt = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "role_permission WHERE role_uuid = :r"
        );
        $stmt->execute(['r' => $roleUuid]);
    }

    /**
     * @param list<string> $permissionUuids
     * @throws RecordInUseException
     */
    public function addPermissions(string $roleUuid, array $permissionUuids): void
    {
        $unique = array_values(array_unique($permissionUuids));

        if ($unique === []) {
            return;
        }

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
}
