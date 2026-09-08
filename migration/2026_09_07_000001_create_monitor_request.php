<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * monitor_request — izlenen her HTTP isteği için bir satır.
 *
 * `request_id`, bootstrap'ta üretilip `X-Request-ID` header'ı ile istemciye
 * dönen ve tüm log satırlarının context'ine eklenen değerdir. Monitor'ün üç
 * tablosu bu anahtarla ilişkilenir ve `logs/app.log` ile korelasyon kurulur.
 *
 * Yabancı anahtar KULLANILMAZ (monitor_query/monitor_exception → bu tablo).
 * İki gerekçe: (1) `monitor:purge` retention silmesini FK kontrolü olmadan
 * parçalı ve hızlı yapmalıdır; (2) izleme tabloları uygulama şemasına
 * kilitlenmemeli — monitor'ü tamamen kaldırmak `DROP TABLE` kadar basit
 * olmalıdır. İlişki mantıksaldır ve silme sırası kod tarafından korunur.
 */
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

            // Sorgu sayaçları saklanan detay satırlarından bağımsızdır:
            // MONITOR_MAX_QUERIES detayı kesse bile gerçek toplam burada durur.
            $table->integer('query_count')->unsigned()->default(0);
            $table->decimal('query_time_ms', 10, 2)->default(0);
            $table->integer('cache_hits')->unsigned()->default(0);
            $table->integer('cache_misses')->unsigned()->default(0);
            $table->boolean('n_plus_one')->default(false);

            $table->string('ip', 45)->nullable();
            $table->char('user_uuid', 36)->nullable();

            // Maskelenmiş gövdeler. MySQL'de LONGTEXT satır dışında saklanır,
            // dolayısıyla bu kolonları seçmeyen liste sorguları onları okumaz.
            $table->json('headers')->nullable();
            $table->longText('request_body')->nullable();
            $table->longText('response_body')->nullable();

            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // bigIncrements() birincil anahtarı kendi ayarlar; primary() ile
            // tekrar bildirmek gerekmez.

            // Detay sayfası bu anahtarla arar; aynı zamanda tekrar yazımı önler.
            $table->unique('request_id', 'uq_' . $this->prefix() . 'monitor_request_rid');

            // Retention silmesi ve zaman aralığı filtresi.
            $table->index('created_at', 'ix_' . $this->prefix() . 'monitor_request_created');
            // "Sadece hatalar" görünümü.
            $table->index(['status', 'created_at'], 'ix_' . $this->prefix() . 'monitor_request_status');
            // Endpoint bazlı inceleme. uri tam uzunlukta indekslenemez
            // (MySQL 3072 byte sınırı), bu yüzden prefix uzunluğu yerine
            // route_name tercih edilir; serbest uri araması her zaman bir
            // created_at aralığı ile birlikte çalıştırılır.
            $table->index(['route_name', 'created_at'], 'ix_' . $this->prefix() . 'monitor_request_route');
            // En yavaş istekler sıralaması.
            $table->index('duration_ms', 'ix_' . $this->prefix() . 'monitor_request_duration');
            // Kullanıcı bazlı inceleme ("bu kullanıcı ne yaptı?").
            $table->index(['user_uuid', 'created_at'], 'ix_' . $this->prefix() . 'monitor_request_user');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('monitor_request');
    }
};
