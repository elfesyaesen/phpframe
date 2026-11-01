<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->hasTable('password_reset')) {
            return;
        }

        if ($this->hasColumn('password_reset', 'code_hash')) {
            return;
        }

        $this->schema->table('password_reset', function (Blueprint $table) {
            $table->char('code_hash', 64);

            $table->tinyInteger('attempts')->unsigned()->default(0);
        });
    }

    public function down(): void
    {
        if (!$this->hasTable('password_reset')) {
            return;
        }

        $this->schema->table('password_reset', function (Blueprint $table) {
            $table->dropColumn(['code_hash', 'attempts']);
        });
    }
};
