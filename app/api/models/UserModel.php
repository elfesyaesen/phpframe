<?php

namespace App\Api\Models;

use App\Api\Exceptions\DuplicateNameException;
use PDOException;
use System\Engine\BaseModel;
use System\Helpers\Uuid;

class UserModel extends BaseModel
{
    public function users(): array
    {
        $sql = "SELECT uuid, username, email, firstname, lastname, phone, created_at, updated_at
                FROM " . $this->prefix() . "user
                WHERE deleted_at IS NULL";
        $statement = $this->pdo()->query($sql);
        return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getUserByEmail(string $email): array|false
    {
        $sql = "SELECT uuid, username, email FROM " . $this->prefix() . "user
                WHERE email = :email AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);

        return $user ?: false;
    }

    public function getUserPassword(string $userUuid): string|false
    {
        $sql = "SELECT password FROM " . $this->prefix() . "user
                WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['uuid' => $userUuid]);
        $result = $statement->fetch(\PDO::FETCH_ASSOC);

        return $result ? $result['password'] : false;
    }

    public function updatePassword(string $userUuid, string $hashedPassword): bool
    {
        $sql = "UPDATE " . $this->prefix() . "user SET password = :password, updated_at = NOW()
                WHERE uuid = :uuid AND deleted_at IS NULL";
        $statement = $this->pdo()->prepare($sql);

        try {
            $statement->execute([
                'password' => $hashedPassword,
                'uuid'     => $userUuid,
            ]);
            return $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @throws DuplicateNameException
     */
    public function storeUser(array $data): string
    {
        $uuid = Uuid::generate();

        $sql = "INSERT INTO " . $this->prefix() . "user
                (uuid, username, email, password, firstname, lastname, phone)
                VALUES (:uuid, :username, :email, :password, :firstname, :lastname, :phone)";

        $statement = $this->pdo()->prepare($sql);

        try {
            $statement->execute([
                'uuid'      => $uuid,
                'username'  => $data['username'],
                'email'     => $data['email'],
                'password'  => $data['password'],
                'firstname' => $data['firstname'] ?? null,
                'lastname'  => $data['lastname'] ?? null,
                'phone'     => $data['phone'] ?? null,
            ]);
            return $uuid;
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                $detail = (string) ($e->errorInfo[2] ?? '');

                if (str_contains($detail, 'user_email')) {
                    throw new DuplicateNameException((string) $data['email'], 'email', $e);
                }

                if (str_contains($detail, 'user_username')) {
                    throw new DuplicateNameException((string) $data['username'], 'username', $e);
                }
            }

            throw $e;
        }
    }

    public function getProfile(string $userUuid): array|false
    {
        $sql = "SELECT uuid, username, email, firstname, lastname, phone,
                       avatar_key, avatar_media_uuid, created_at, updated_at
                FROM " . $this->prefix() . "user
                WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['uuid' => $userUuid]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        return $this->hydrateProfile($row);
    }

    /**
     * @param array<string,mixed> $set
     */
    public function updateProfile(string $userUuid, array $set): bool
    {
        $allowed = ['firstname', 'lastname', 'phone'];
        $sets = [];
        $params = ['uuid' => $userUuid];

        foreach ($set as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if (empty($sets)) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';

        $sql = "UPDATE " . $this->prefix() . "user SET " . implode(', ', $sets)
             . " WHERE uuid = :uuid AND deleted_at IS NULL";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount() > 0;
    }

    /**
     * @return array{media_uuid: ?string, legacy_key: ?string}|null
     */
    public function getAvatarRefs(string $userUuid): ?array
    {
        $sql = "SELECT avatar_media_uuid, avatar_key FROM " . $this->prefix() . "user
                WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['uuid' => $userUuid]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'media_uuid' => !empty($row['avatar_media_uuid']) ? (string) $row['avatar_media_uuid'] : null,
            'legacy_key' => !empty($row['avatar_key']) ? (string) $row['avatar_key'] : null,
        ];
    }

    public function updateAvatarMedia(string $userUuid, ?string $mediaUuid): bool
    {
        $sql = "UPDATE " . $this->prefix() . "user
                SET avatar_media_uuid = :media_uuid, avatar_key = NULL, updated_at = NOW()
                WHERE uuid = :uuid AND deleted_at IS NULL";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute([
                'media_uuid' => $mediaUuid,
                'uuid'       => $userUuid,
            ]);
            return $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    public function softDeleteUser(string $userUuid): bool
    {
        $sql = "UPDATE " . $this->prefix() . "user
                SET deleted_at = NOW(), updated_at = NOW()
                WHERE uuid = :uuid AND deleted_at IS NULL";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute(['uuid' => $userUuid]);
            return $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    private function hydrateProfile(array $r): array
    {
        return [
            'uuid'       => $r['uuid'],
            'username'   => $r['username'],
            'email'      => $r['email'],
            'firstname'  => $r['firstname'],
            'lastname'   => $r['lastname'],
            'phone'      => $r['phone'],

            'avatar_url' => !empty($r['avatar_media_uuid']) || !empty($r['avatar_key']) ? '/v1/api/users/me/avatar' : null,
            'avatar_media_uuid' => $r['avatar_media_uuid'] ?? null,
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }
}
