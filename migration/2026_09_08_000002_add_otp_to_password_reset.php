<?php

declare(strict_types=1);

use System\Database\Blueprint;
use System\Database\Migration;

/**
 * Parola sıfırlamaya OTP kodu ekler — link'in YANINA, YERİNE DEĞİL.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN İKİSİ BİRDEN: API mobil-only. Link'in çalışması için ya bir web
 * sayfası ya da universal/app link kurulumu gerekir; kod ise hiçbir şey
 * gerektirmez — kullanıcı mail'deki 6 haneyi uygulamaya yazar. Mail her
 * ikisini de taşır, kullanıcı hangisi işine geliyorsa onu kullanır:
 *
 *   • Link  → mail'i telefonda açan kullanıcı için tek dokunuş
 *   • Kod   → mail'i bilgisayarda açan, ya da uygulama kurulu olmayan,
 *             ya da link'i tıklanamaz gösteren bir mail istemcisi kullanan
 *             kullanıcı için tek çıkış yolu
 *
 * TEK KAYIT, İKİ ANAHTAR: aynı satır hem `token_hash` hem `code_hash`
 * taşır. Ayrı satırlar olsaydı biri tüketilirken diğerini de silmek
 * gerekirdi ve o iki silme arasındaki pencerede kod hâlâ geçerli olurdu.
 *
 * ── `attempts` NEDEN ZORUNLU ────────────────────────────────────────────
 *
 * Token 256 bit CSPRNG, kaba kuvvetle bulunamaz. Kod ise 6 hane, yani
 * 10^6 = bir milyon olasılık — hedefli bir saldırgan için erişilebilir bir
 * sayı. Rota bazlı rate limit YETMEZ: IP döndürerek aşılır.
 *
 * Bu yüzden sayaç TOKEN'IN KENDİSİNDE tutulur. `PASSWORD_RESET_MAX_ATTEMPTS`
 * hatalı denemeden sonra kayıt SİLİNİR; saldırgan kaç IP kullanırsa kullansın
 * o sıfırlama talebi için bütçesi biter. Kullanıcı yeniden talep eder.
 *
 * Sayaç aşıldığında LİNK DE ÖLÜR — kasıtlı. Kod üzerinde kaba kuvvet
 * denemesi görülüyorsa o sıfırlama talebinin tamamı şüphelidir; kullanıcının
 * yeniden talep etmesi ucuz, yanlış tarafta hata yapmak pahalı.
 * ─────────────────────────────────────────────────────────────────────────
 */
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
            // SHA-256 hex = 64 karakter — `token_hash` ile aynı biçim.
            //
            // Kod da HASH'LENİR, düz metin saklanmaz. "Nasılsa 6 hane, DB
            // sızarsa zaten süresi dolar" akıl yürütmesi yanlış: sızıntı ile
            // fark edilmesi arasındaki pencerede geçerli kodlar okunabilir.
            //
            // `password_hash` DEĞİL, SHA-256 — bilinçli. Yavaş hash'in
            // koruduğu şey sözlük saldırısı; burada uzay 10^6 ve zaten
            // `attempts` ile kapatılıyor. Sabit uzunluklu hash ise tek
            // sorguda karşılaştırmayı mümkün kılıyor.
            $table->char('code_hash', 64);

            // UNSIGNED TINYINT: 0-255 aralığı, sayaç için fazlasıyla yeterli.
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
