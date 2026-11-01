<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('users_token')) {
            return;
        }

        $this->schema->create('users_token', function (Blueprint $table) {
            $table->char('uuid', 36);
            $table->char('user_uuid', 36);
            $table->string('access_token_hash', 64)->nullable();
            $table->string('refresh_token_hash', 64)->nullable();
            $table->string('previous_refresh_token_hash', 64)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->primary('uuid');
            $table->unique('access_token_hash', 'uq_' . $this->prefix() . 'users_token_access_token_hash');
            $table->foreign('user_uuid')
                  ->references('uuid')->on('user')
                  ->onDelete('CASCADE')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'users_token_user');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('users_token');
    }
};
