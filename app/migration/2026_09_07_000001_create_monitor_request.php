<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('monitor_request')) {
            return;
        }

        $this->schema->create('monitor_request', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('request_id', 64);
            $table->string('method', 10);
            $table->string('uri', 2048);
            $table->string('route_name', 150)->nullable();
            $table->smallInteger('status')->unsigned();
            $table->decimal('duration_ms', 10, 2);
            $table->integer('memory_kb')->unsigned();

            $table->integer('query_count')->unsigned()->default(0);
            $table->decimal('query_time_ms', 10, 2)->default(0);
            $table->integer('cache_hits')->unsigned()->default(0);
            $table->integer('cache_misses')->unsigned()->default(0);
            $table->boolean('n_plus_one')->default(false);

            $table->string('ip', 45)->nullable();
            $table->char('user_uuid', 36)->nullable();

            $table->json('headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->longText('response_body')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->unique('request_id', 'uq_' . $this->prefix() . 'monitor_request_rid');

            $table->index('created_at', 'ix_' . $this->prefix() . 'monitor_request_created');
            $table->index(['status', 'created_at'], 'ix_' . $this->prefix() . 'monitor_request_status');

            $table->index(['route_name', 'created_at'], 'ix_' . $this->prefix() . 'monitor_request_route');
            $table->index('duration_ms', 'ix_' . $this->prefix() . 'monitor_request_duration');
            $table->index(['user_uuid', 'created_at'], 'ix_' . $this->prefix() . 'monitor_request_user');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('monitor_request');
    }
};
