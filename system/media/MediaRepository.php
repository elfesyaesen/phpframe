<?php

declare(strict_types=1);

namespace System\Media;

use System\Engine\BaseModel;

final class MediaRepository extends BaseModel
{
    private const SELECT = 'id, uuid, collection, visibility, owner_type, owner_ref, mime, size, width, height,'
        . ' sha256, original_name, variants, created_by, created_at, deleted_at';

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        $this->pdo()->prepare(
            'INSERT INTO ' . $this->table()
            . ' (uuid, collection, visibility, owner_type, owner_ref, mime, size, width, height,'
            . ' sha256, original_name, variants, created_by)'
            . ' VALUES (:uuid, :collection, :visibility, :owner_type, :owner_ref, :mime, :size, :width, :height,'
            . ' :sha256, :original_name, :variants, :created_by)'
        )->execute($data);
    }

    public function find(string $uuid): ?Media
    {
        $row = $this->selectOne(
            'SELECT ' . self::SELECT . ' FROM ' . $this->table() . ' WHERE uuid = :uuid AND deleted_at IS NULL',
            ['uuid' => strtolower($uuid)]
        );

        return $row === null ? null : Media::fromRow($row);
    }

    public function usedBytes(string $userUuid): int
    {
        $row = $this->selectOne(
            'SELECT COALESCE(SUM(size), 0) AS total FROM ' . $this->table()
            . ' WHERE created_by = :user AND deleted_at IS NULL',
            ['user' => $userUuid]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function softDelete(string $uuid): bool
    {
        $statement = $this->pdo()->prepare(
            'UPDATE ' . $this->table() . ' SET deleted_at = CURRENT_TIMESTAMP WHERE uuid = :uuid AND deleted_at IS NULL'
        );
        $statement->execute(['uuid' => strtolower($uuid)]);

        return $statement->rowCount() > 0;
    }

    private function table(): string
    {
        return $this->prefix() . 'media';
    }
}
