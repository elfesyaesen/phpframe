<?php

declare(strict_types=1);

use System\Database\Migration;
use System\Helpers\Uuid;

return new class extends Migration
{
    private const SAMPLE_PERMISSIONS = [
        'users.read'   => 'Kullanıcıları görüntüle',
        'users.create' => 'Kullanıcı oluştur',
        'users.update' => 'Kullanıcı güncelle',
        'users.delete' => 'Kullanıcı sil',
    ];

    public function up(): void
    {
        $this->seedPermissions();
        $this->seedRole('administrator', true);
        $this->seedRole('user', false);
    }

    public function down(): void
    {
    }

    private function seedPermissions(): void
    {
        foreach (self::SAMPLE_PERMISSIONS as $name => $description) {
            if ($this->exists('permission', 'name', $name)) {
                continue;
            }
            $this->execute(
                "INSERT INTO " . $this->prefix() . "permission (uuid, name, description)
                 VALUES (:uuid, :name, :description)",
                ['uuid' => Uuid::generate(), 'name' => $name, 'description' => $description]
            );
        }
    }

    private function seedRole(string $name, bool $superadmin): void
    {
        if ($this->exists('role', 'name', $name)) {
            return;
        }

        $this->execute(
            "INSERT INTO " . $this->prefix() . "role (uuid, name, superadmin)
             VALUES (:uuid, :name, :superadmin)",
            ['uuid' => Uuid::generate(), 'name' => $name, 'superadmin' => $superadmin ? 1 : 0]
        );
    }

    private function exists(string $table, string $column, string $value): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM " . $this->prefix() . $table . " WHERE {$column} = :v"
        );
        $stmt->execute(['v' => $value]);

        return (int) $stmt->fetchColumn() > 0;
    }
};
