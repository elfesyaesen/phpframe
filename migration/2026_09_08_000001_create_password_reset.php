<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * Parola sıfırlama token'ları.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN: eski akış YENİ PAROLAYI E-POSTAYLA GÖNDERİYORDU. İki kapatılamaz
 * sorunu vardı:
 *
 *  1. Parola, e-posta arşivinde KALICI olarak düz metin durur. Posta hesabı
 *     ele geçirilirse uygulama hesabı da gider — kullanıcı parolasını
 *     değiştirmiş olsa bile eski mesaj hâlâ oradadır.
 *
 *  2. "Mail gitti ama commit patladı" penceresi transaction'la DARALTILDI
 *     ama YOK EDİLEMEDİ: e-posta teslimi geri alınamaz. Kullanıcıya
 *     kendisinin olmayan bir parola gönderilmiş olabilir.
 *
 * Token akışı ikisini birden çözer: e-postada parola değil TEK KULLANIMLIK
 * BİR LİNK gider. Link kullanılana kadar hesap DEĞİŞMEZ, dolayısıyla
 * "mail gitti, DB patladı" durumu zararsızdır — token kullanılmaz, süresi
 * doler, hesap eski parolayla çalışmaya devam eder.
 *
 * ── TASARIM KARARLARI ───────────────────────────────────────────────────
 *
 * `token_hash` SAKLANIR, TOKEN SAKLANMAZ. DB sızarsa token'lar kullanılamaz.
 * `users_token` tablosundaki access/refresh hash'leriyle aynı ilke.
 *
 * `user_uuid` UNIQUE DEĞİL: kullanıcı üst üste talep gönderebilir. Tüketim
 * anında o kullanıcının TÜM token'ları silinir, yani eski linkler ölür.
 *
 * `used_at` sütunu YOK — kullanılan token SİLİNİR. Tek kullanımlık bir sırrın
 * hash'ini "kullanıldı" işaretiyle saklamak, temizlenmesi gereken kalıcı bir
 * veri bırakır ve hiçbir şey kazandırmaz.
 * ─────────────────────────────────────────────────────────────────────────
 */
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

            // SHA-256 hex = 64 karakter. UNIQUE: aynı hash iki kez var olamaz
            // ve arama bu indeks üzerinden yapılır.
            $table->string('token_hash', 64);

            $table->timestamp('expires_at');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->primary('uuid');
            $table->unique('token_hash', 'uq_' . $this->prefix() . 'password_reset_token_hash');

            // Süresi geçmiş kayıtların toplu temizliği için.
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
