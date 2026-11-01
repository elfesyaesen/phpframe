<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('permission')) {
            return;
        }

        $this->schema->create('permission', function (Blueprint $table) {
            $table->char('uuid', 36);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->primary('uuid');
            $table->unique('name', 'uq_' . $this->prefix() . 'permission_name');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('permission');
    }
};
