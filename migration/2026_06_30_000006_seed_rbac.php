<?php

declare(strict_types=1);

use System\Database\Migration;
use System\Helpers\Uuid;

/**
 * RBAC yapısal başlangıç verisi (rol & yetki — KULLANICI verisi DEĞİL).
 *
 * - role: administrator (is_superadmin=1 → tüm yetkiler) + user (taban, yetkisiz).
 * - permission: birkaç örnek yetki (sonradan eklenip çıkarılabilir).
 *
 * Varsayılan admin KULLANICISI bilinçli olarak seed EDİLMEZ (public framework'te
 * gömülü kimlik bilgisi shiplenmez). İlk admin, bir kullanıcıya administrator rolü
 * atanarak yapılır (tr_user_role kaydı) — örn. doğrudan DB üzerinden.
 *
 * administrator superadmin olduğundan örnek yetkilerle eşlenmesine gerek yoktur;
 * her yetkiyi (sonradan eklenenler dahil) otomatik kapsar.
 *
 * Hepsi var-mı kontrolüyle idempotenttir.
 */
return new class extends Migration
{
    /** Örnek yetkiler — yönetim arayüzünden/komutla ekleyip çıkarılabilir. */
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
        // Yapısal seed: rol/permission tabloları kendi migration'larında drop edilir.
        // Burada geri alınacak kullanıcı verisi yoktur.
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
            "INSERT INTO " . $this->prefix() . "role (uuid, name, is_superadmin)
             VALUES (:uuid, :name, :is_superadmin)",
            ['uuid' => Uuid::generate(), 'name' => $name, 'is_superadmin' => $superadmin ? 1 : 0]
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
