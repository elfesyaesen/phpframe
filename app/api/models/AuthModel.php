<?php
namespace App\Api\Models;

use System\Engine\BaseModel;
use System\Helpers\Uuid;
use PDOException;

class AuthModel extends BaseModel
{

    public function authenticate(string $email, string $password): array|false
    {
        if (empty($email)) {
            return false;
        }

        $sql = "SELECT uuid, username, email, password, firstname, lastname, phone, created_at, updated_at
                FROM " . $this->prefix() . "user WHERE email = :email AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }
        unset($user['password']);
        return $user;
    }

    public function getUserByUuid(string $userUuid): array|false
    {
        $sql = "SELECT uuid, username, email, firstname, lastname, phone, created_at, updated_at
                FROM " . $this->prefix() . "user WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['uuid' => $userUuid]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return false;
        }

        return $user;
    }

    public function replaceToken(string $userUuid, string $accessHash, string $refreshHash): void
    {
        $delete = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "users_token WHERE user_uuid = :user_uuid"
        );
        $delete->execute(['user_uuid' => $userUuid]);

        $insert = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "users_token
             (uuid, user_uuid, refresh_token_hash, access_token_hash)
             VALUES (:uuid, :user_uuid, :refresh_token_hash, :access_token_hash)"
        );

        $insert->execute([
            'uuid'               => Uuid::generate(),
            'user_uuid'          => $userUuid,
            'refresh_token_hash' => $refreshHash,
            'access_token_hash'  => $accessHash,
        ]);
    }

    public function getUserToken(string $userUuid): array|false
    {
        $sql = "SELECT uuid, user_uuid, access_token_hash, refresh_token_hash, previous_refresh_token_hash, created_at, updated_at
                FROM " . $this->prefix() . "users_token WHERE user_uuid = :user_uuid LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['user_uuid' => $userUuid]);
        return $statement->fetch(\PDO::FETCH_ASSOC) ?: false;
    }

    public function removeRefreshToken(string $userUuid): bool
    {
        $sql = "DELETE FROM " . $this->prefix() . "users_token WHERE user_uuid = :user_uuid";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute(['user_uuid' => $userUuid]);
            return $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    public function rotateRefreshToken(
        string $userUuid,
        string $oldRefreshTokenHash,
        string $newAccessTokenHash,
        string $newRefreshTokenHash
    ): bool {
        $sql = "UPDATE " . $this->prefix() . "users_token
                SET previous_refresh_token_hash = refresh_token_hash,
                    refresh_token_hash          = :new_refresh_hash,
                    access_token_hash           = :new_access_hash,
                    updated_at                  = NOW()
                WHERE user_uuid          = :user_uuid
                  AND refresh_token_hash = :old_refresh_hash";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute([
                'user_uuid'        => $userUuid,
                'old_refresh_hash' => $oldRefreshTokenHash,
                'new_access_hash'  => $newAccessTokenHash,
                'new_refresh_hash' => $newRefreshTokenHash,
            ]);
            return $statement->rowCount() > 0;
        } catch (PDOException) {
            return false;
        }
    }

    public function isRefreshTokenReused(string $userUuid, string $refreshTokenHash): bool
    {
        $sql = "SELECT 1 FROM " . $this->prefix() . "users_token
                WHERE user_uuid                   = :user_uuid
                  AND previous_refresh_token_hash = :hash
                LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute([
                'user_uuid' => $userUuid,
                'hash'      => $refreshTokenHash,
            ]);
            return (bool) $statement->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }

    public function validAccessToken(string $userUuid, string $accessTokenHash): bool
    {
        $sql = "SELECT 1 FROM " . $this->prefix() . "users_token
                WHERE user_uuid         = :user_uuid
                  AND access_token_hash = :hash
                LIMIT 1";
        $statement = $this->pdo()->prepare($sql);
        try {
            $statement->execute([
                'user_uuid' => $userUuid,
                'hash'      => $accessTokenHash,
            ]);
            return (bool) $statement->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }
}
