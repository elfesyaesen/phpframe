<?php

declare(strict_types=1);

namespace App\Api\Models;

use App\Api\Exceptions\DuplicateNameException;
use PDOException;
use System\Engine\BaseModel;
use System\Helpers\Uuid;

class PermissionModel extends BaseModel
{
    public const EFFECT_GRANT = 'grant';
    public const EFFECT_DENY  = 'deny';

    /**
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
            if (($row['effect'] ?? null) === self::EFFECT_DENY) {
                $deny[] = (string) $row['name'];
            } else {
                $grant[] = (string) $row['name'];
            }
        }

        return ['grant' => $grant, 'deny' => $deny];
    }

    /**
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
     * @throws DuplicateNameException
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

            throw $e;
        }
    }

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

    public function setUserOverride(string $userUuid, string $permissionUuid, ?string $effect): bool
    {
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

                return true;
            }

            $stmt = $this->pdo()->prepare(
                "INSERT INTO " . $this->prefix() . "user_permission (user_uuid, permission_uuid, effect)
                 VALUES (:u, :p, :e)
                 ON DUPLICATE KEY UPDATE effect = VALUES(effect)"
            );

            return $stmt->execute(['u' => $userUuid, 'p' => $permissionUuid, 'e' => $effect]);
        } catch (PDOException $e) {
            if ($this->isForeignKeyViolation($e)) {
                return false;
            }

            throw $e;
        }
    }
}
