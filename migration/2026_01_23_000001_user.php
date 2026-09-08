<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * user tablosu — kimlik + profil çekirdeği.
 *
 * Tek dosyada konsolide edilmiştir (avatar, soft-delete, unique-active).
 * Yetkilendirme (rol/izin) bu tabloda DEĞİL; normalize RBAC tablolarındadır
 * (role / permission / role_permission / user_role / user_permission).
 *
 * Sürücü-bağımsız Schema builder ile yazılır; MySQL (InnoDB/utf8mb4) ve
 * PostgreSQL için doğru DDL üretir.
 *
 * - uuid: CHAR(36) birincil anahtar, uygulama tarafında üretilir (Uuid::generate).
 *   DB'ye özgü bir varsayılana (gen_random_uuid) bağımlılık yoktur.
 * - deleted_at: soft-delete. email/username unique index'leri normal unique'tir;
 *   silinen bir kaydın email/username'i tekrar kullanılamaz (MySQL filtered index
 *   desteklemediği için partial-unique yerine bu tercih edilmiştir).
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('user')) {
            return;
        }

        $this->schema->create('user', function (Blueprint $table) {
            $table->char('uuid', 36);
            $table->string('username', 50);
            $table->string('email', 100);
            $table->string('password', 255);
            $table->string('firstname', 100)->nullable();
            $table->string('lastname', 100)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('avatar_key', 255)->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->primary('uuid');
            $table->unique('email',    'uq_' . $this->prefix() . 'user_email');
            $table->unique('username', 'uq_' . $this->prefix() . 'user_username');
            $table->index('deleted_at', 'idx_' . $this->prefix() . 'user_deleted_at');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('user');
    }
};
