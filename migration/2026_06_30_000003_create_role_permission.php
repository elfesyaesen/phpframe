<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * role_permission — rol ↔ yetki (çok-çok) eşlemesi.
 *
 * Bileşik birincil anahtar (role_uuid, permission_uuid) tekrarı önler. Rol veya
 * yetki silinince eşleme CASCADE ile temizlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('role_permission')) {
            return;
        }

        $this->schema->create('role_permission', function (Blueprint $table) {
            $table->char('role_uuid', 36);
            $table->char('permission_uuid', 36);

            $table->primary(['role_uuid', 'permission_uuid']);
            $table->foreign('role_uuid')
                  ->references('uuid')->on('role')
                  ->onDelete('CASCADE')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'role_permission_role');
            $table->foreign('permission_uuid')
                  ->references('uuid')->on('permission')
                  ->onDelete('CASCADE')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'role_permission_permission');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('role_permission');
    }
};
