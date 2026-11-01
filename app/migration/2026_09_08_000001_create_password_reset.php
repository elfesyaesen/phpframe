<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('password_reset')) {
            return;
        }

        $this->schema->create('password_reset', function (Blueprint $table) {
            $table->char('uuid', 36);
            $table->char('user_uuid', 36);

            $table->string('token_hash', 64);

            $table->timestamp('expires_at');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->primary('uuid');
            $table->unique('token_hash', 'uq_' . $this->prefix() . 'password_reset_token_hash');

            $table->index('expires_at', 'ix_' . $this->prefix() . 'password_reset_expires_at');

            $table->foreign('user_uuid')
                  ->references('uuid')->on('user')
                  ->onDelete('CASCADE')->onUpdate('CASCADE')
                  ->name('fk_' . $this->prefix() . 'password_reset_user');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('password_reset');
    }
};
