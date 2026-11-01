<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->hasTable('user') || $this->hasColumn('user', 'avatar_media_uuid')) {
            return;
        }

        $this->schema->table('user', function (Blueprint $table) {
            $table->char('avatar_media_uuid', 36)->nullable();
        });
    }

    public function down(): void
    {
        if (!$this->hasTable('user') || !$this->hasColumn('user', 'avatar_media_uuid')) {
            return;
        }

        $this->schema->table('user', function (Blueprint $table) {
            $table->dropColumn(['avatar_media_uuid']);
        });
    }
};
