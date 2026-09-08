<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * user_permission — kullanıcıya özel yetki override'ı.
 *
 * effect = 'grant' (+) → role'ün dışında EK yetki verir.
 * effect = 'deny'  (−) → role versede yetkiyi GERİ ALIR (deny her zaman kazanır).
 *
 * Bileşik PK (user_uuid, permission_uuid): bir kullanıcı-yetki çifti için tek
 * effect. Kullanıcı veya yetki silinince CASCADE.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('user_permission')) {
            return;
        }

        $this->schema->create('user_permission', function (Blueprint $table) {
            $table->char('user_uuid', 36);
            $table->char('permission_uuid', 36);
            $table->enum('effect', ['grant', 'deny']);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->primary(['user_uuid', 'permission_uuid']);
            $table->foreign('user_uuid')
                  ->references('uuid')->on('user')
                  ->onDelete('CASCADE')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'user_permission_user');
            $table->foreign('permission_uuid')
                  ->references('uuid')->on('permission')
                  ->onDelete('CASCADE')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'user_permission_permission');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('user_permission');
    }
};
