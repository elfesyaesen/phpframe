<?php

declare(strict_types=1);

namespace App\Api\Models;

use System\Engine\BaseModel;
use System\Helpers\Uuid;

class PasswordResetModel extends BaseModel
{
    /**
     * @param string $tokenHash
     * @param string $codeHash
     * @param int $expiresAt
     */
    public function store(
        string $userUuid,
        string $tokenHash,
        string $codeHash,
        int $expiresAt,
        int $attempts = 0,
    ): void {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO " . $this->prefix() . "password_reset
             (uuid, user_uuid, token_hash, code_hash, expires_at, attempts)
             VALUES (:uuid, :user_uuid, :token_hash, :code_hash, FROM_UNIXTIME(:expires_at), :attempts)"
        );

        $stmt->execute([
            'uuid'       => Uuid::generate(),
            'user_uuid'  => $userUuid,
            'token_hash' => $tokenHash,
            'code_hash'  => $codeHash,
            'expires_at' => $expiresAt,
            'attempts'   => max(0, $attempts),
        ]);
    }

    /**
     * @return array{uuid: string, user_uuid: string}|null
     */
    public function findValid(string $tokenHash, int $max): ?array
    {
        /** @var array{uuid: string, user_uuid: string}|null $row */
        $row = $this->selectOne(
            "SELECT uuid, user_uuid
             FROM " . $this->prefix() . "password_reset
             WHERE token_hash = :hash AND attempts < :max AND expires_at > NOW()
             LIMIT 1",
            ['hash' => $tokenHash, 'max' => $max]
        );

        return $row;
    }

    /**
     * @return array{uuid: string, code_hash: string}|null
     */
    public function findValidForUser(string $userUuid, int $max): ?array
    {
        /** @var array{uuid: string, code_hash: string}|null $row */
        $row = $this->selectOne(
            "SELECT uuid, code_hash
             FROM " . $this->prefix() . "password_reset
             WHERE user_uuid = :user_uuid AND attempts < :max AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 1",
            ['user_uuid' => $userUuid, 'max' => $max]
        );

        if ($row === null) {
            return null;
        }

        return [
            'uuid'      => (string) $row['uuid'],
            'code_hash' => (string) $row['code_hash'],
        ];
    }

    /**
     * @return bool
     */
    public function reserveAttempt(string $uuid, int $max): bool
    {
        $stmt = $this->pdo()->prepare(
            "UPDATE " . $this->prefix() . "password_reset
             SET attempts = attempts + 1
             WHERE uuid = :uuid AND attempts < :max AND expires_at > NOW()"
        );
        $stmt->execute(['uuid' => $uuid, 'max' => $max]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @return bool
     */
    public function consume(string $uuid): bool
    {
        $stmt = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "password_reset
             WHERE uuid = :uuid AND expires_at > NOW()"
        );
        $stmt->execute(['uuid' => $uuid]);

        return $stmt->rowCount() === 1;
    }

    public function attemptsUsed(string $userUuid): int
    {
        $rows = $this->selectAll(
            "SELECT attempts
             FROM " . $this->prefix() . "password_reset
             WHERE user_uuid = :user_uuid AND expires_at > NOW()
             FOR UPDATE",
            ['user_uuid' => $userUuid]
        );

        return array_reduce(
            $rows,
            static fn (int $carry, array $row): int => max($carry, (int) $row['attempts']),
            0
        );
    }

    public function deleteForUser(string $userUuid): void
    {
        $stmt = $this->pdo()->prepare(
            "DELETE FROM " . $this->prefix() . "password_reset WHERE user_uuid = :user_uuid"
        );
        $stmt->execute(['user_uuid' => $userUuid]);
    }
}
