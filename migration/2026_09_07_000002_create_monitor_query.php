<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * monitor_query — bir istek içinde çalışan SQL sorguları.
 *
 * İsteğe bağlı olarak dolar (MONITOR_RECORD_QUERIES) ve istek başına
 * MONITOR_MAX_QUERIES satırla sınırlıdır. `monitor_request.query_count`
 * gerçek toplamı tuttuğu için bu sınır metrik doğruluğunu bozmaz, yalnızca
 * detayı kırpar.
 *
 * `bindings` maskelenmiş olarak saklanır: sorgu parametreleri parola hash'i,
 * token veya kart numarası taşıyabilir.
 */
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

            // Detay sayfası: bir isteğin tüm sorguları.
            $table->index('request_id', 'ix_' . $this->prefix() . 'monitor_query_rid');
            // Retention silmesi.
            $table->index('created_at', 'ix_' . $this->prefix() . 'monitor_query_created');
            // "En yavaş sorgular" raporu.
            $table->index('duration_ms', 'ix_' . $this->prefix() . 'monitor_query_duration');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('monitor_query');
    }
};
