<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('user_role')) {
            return;
        }

        $this->schema->create('user_role', function (Blueprint $table) {
            $table->char('user_uuid', 36);
            $table->char('role_uuid', 36);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->primary('user_uuid');
            $table->foreign('user_uuid')
                  ->references('uuid')->on('user')
                  ->onDelete('CASCADE')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'user_role_user');
            $table->foreign('role_uuid')
                  ->references('uuid')->on('role')
                  ->onDelete('RESTRICT')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'user_role_role');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('user_role');
    }
};
