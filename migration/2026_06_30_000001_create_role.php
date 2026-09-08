<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * role (yetki grubu) tablosu.
 *
 * Bir rol bir yetki kümesini (role_permission) gruplar. Kullanıcı tek bir role
 * sahiptir (user_role). is_superadmin=1 olan rol (administrator) TÜM yetkilere
 * sahiptir; sonradan eklenen yetkiler dahil — tek tek atama gerekmez.
 *
 * Roller çalışma zamanında eklenip çıkarılabilir (veri).
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('role')) {
            return;
        }

        $this->schema->create('role', function (Blueprint $table) {
            $table->char('uuid', 36);
            $table->string('name', 100);
            $table->boolean('is_superadmin')->default(false);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->primary('uuid');
            $table->unique('name', 'uq_' . $this->prefix() . 'role_name');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('role');
    }
};
