<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * monitor_exception — istek sırasında oluşan hatalar.
 *
 * İki kaynaktan beslenir:
 *  - `ExceptionHandler` üzerinden fırlatılan `Throwable`'lar,
 *  - `FatalErrorCollector` üzerinden fatal error'lar (bunlar hiçbir zaman
 *    `Throwable` olmaz, yalnızca `error_get_last()` ile görülebilir).
 *
 * `trace` args İÇERMEZ: `getTrace()`'in 'args' alanı çağrı parametrelerini
 * (parola, SECRET_KEY, token) taşır ve saklanmamalıdır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->hasTable('monitor_exception')) {
            return;
        }

        $this->schema->create('monitor_exception', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('request_id', 64);
            $table->string('exception_class', 255);
            $table->text('message');
            $table->string('file', 512);
            $table->integer('line')->unsigned();
            $table->longText('trace')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->index('request_id', 'ix_' . $this->prefix() . 'monitor_exception_rid');
            $table->index('created_at', 'ix_' . $this->prefix() . 'monitor_exception_created');
            // "Hangi hata türü ne sıklıkta oluyor?" raporu.
            $table->index(['exception_class', 'created_at'], 'ix_' . $this->prefix() . 'monitor_exception_class');
        });
    }

    public function down(): void
    {
        $this->schema->dropIfExists('monitor_exception');
    }
};
