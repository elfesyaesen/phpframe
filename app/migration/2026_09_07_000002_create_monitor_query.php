<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('monitor_query')) {
            return;
        }

        $this->schema->create('monitor_query', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('request_id', 64);
            $table->text('sql_text');
            $table->json('bindings')->nullable();
            $table->decimal('duration_ms', 10, 2);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->index('request_id', 'ix_' . $this->prefix() . 'monitor_query_rid');
            $table->index('created_at', 'ix_' . $this->prefix() . 'monitor_query_created');
            $table->index('duration_ms', 'ix_' . $this->prefix() . 'monitor_query_duration');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('monitor_query');
    }
};
