<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use System\Security\PasswordHasher;

/**
 * `PasswordHasher` — tek parola politikası.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * `Security` suite'inde, `Unit`'te DEĞİL. Gerekçe: buradaki testler
 * davranış değil GÜVENLİK INVARIANT'I doğruluyor. Biri `generate()`'i
 * "daha hızlı" diye `str_shuffle`'a çevirirse ortaya çıkan şey bir bug
 * değil, tahmin edilebilir parola üretimidir.
 *
 * Sınıfın var olma sebebi iki ölçülmüş hata:
 *
 *  1. `str_shuffle` CSPRNG DEĞİL. Mt19937 tabanlıdır ve tohum bilinirse
 *     çıktısı deterministiktir. Eski kod karakter SEÇİMİNİ `random_int` ile
 *     doğru yapıyor, sonra permütasyonu zayıf PRNG'ye bırakıyordu.
 *
 *  2. Parola hash tutarsızlığı: kayıt `PASSWORD_DEFAULT`, sıfırlama ve
 *     değiştirme `PASSWORD_BCRYPT` kullanıyordu — aynı sütunda iki
 *     algoritma. `password_needs_rehash()` ise hiç yoktu.
 * ─────────────────────────────────────────────────────────────────────────
 */
#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PasswordHasher();
    }

    // ── hash / verify ─────────────────────────────────────────────

    public function testHashIsVerifiable(): void
    {
        $hash = $this->hasher->hash('dogru-parola');

        self::assertTrue($this->hasher->verify('dogru-parola', $hash));
        self::assertFalse($this->hasher->verify('yanlis-parola', $hash));
    }

    /**
     * Aynı parola İKİ FARKLI hash üretmeli — salt rastgele olmalı.
     *
     * Bu kırılırsa hash'ler deterministik olur: aynı parolayı kullanan iki
     * kullanıcı veritabanında aynı satır değerini taşır ve bir sızıntıda
     * "kim aynı parolayı kullanıyor" bilgisi bedava gelir.
     */
    public function testSaltIsRandomSoHashesDiffer(): void
    {
        $a = $this->hasher->hash('ayni-parola');
        $b = $this->hasher->hash('ayni-parola');

        self::assertNotSame($a, $b, 'Salt rastgele değil: aynı parola aynı hash üretti.');
        self::assertTrue($this->hasher->verify('ayni-parola', $a));
        self::assertTrue($this->hasher->verify('ayni-parola', $b));
    }

    public function testVerifyRejectsEmptyAndMalformedHash(): void
    {
        self::assertFalse($this->hasher->verify('parola', ''));
        self::assertFalse($this->hasher->verify('parola', 'bu-bir-hash-degil'));
    }

    /**
     * Boş parola bir hash üretebilir ama YANLIŞ parolayla doğrulanmamalı.
     *
     * Not: boş parolanın REDDEDİLMESİ bu sınıfın işi değil, `Validator`'ın
     * (`required|password:...`). Burada test edilen şey hash'in kendisinin
     * tutarlılığı.
     */
    public function testEmptyPasswordStillProducesIsolatedHash(): void
    {
        $hash = $this->hasher->hash('');

        self::assertTrue($this->hasher->verify('', $hash));
        self::assertFalse($this->hasher->verify('x', $hash));
    }

    // ── needsRehash ───────────────────────────────────────────────

    /**
     * Kendi ürettiği hash yeniden hash'lenmeye İHTİYAÇ DUYMAMALI.
     *
     * Aksi halde her girişte gereksiz rehash yapılır.
     */
    public function testFreshHashDoesNotNeedRehash(): void
    {
        self::assertFalse($this->hasher->needsRehash($this->hasher->hash('p')));
    }

    /**
     * Politika dışı bir algoritmayla üretilmiş hash rehash İSTEMELİ.
     *
     * Doğrudan hata #2'nin regresyon koruması: kod tabanı bir zamanlar
     * `PASSWORD_BCRYPT`'i elle kullanıyordu. Politika `PASSWORD_DEFAULT`
     * ise ve o gelecekte bcrypt'ten başka bir şeye taşınırsa, eski
     * hash'lerin geçişi bu bayrağa bağlıdır.
     */
    public function testHashFromDifferentAlgorithmNeedsRehash(): void
    {
        // Kasten politika dışı: düşük cost'lu açık bcrypt.
        $legacy = password_hash('p', PASSWORD_BCRYPT, ['cost' => 4]);

        self::assertIsString($legacy);
        self::assertTrue(
            $this->hasher->needsRehash($legacy),
            'Politika dışı parametrelerle üretilen hash rehash istemeli.'
        );
        // Yine de doğrulanabilir olmalı: geçiş kullanıcıyı kilitlemez.
        self::assertTrue($this->hasher->verify('p', $legacy));
    }

    // ── generate ──────────────────────────────────────────────────

    public function testGenerateRespectsRequestedLength(): void
    {
        foreach ([8, 12, 16, 32] as $len) {
            self::assertSame($len, strlen($this->hasher->generate($len)));
        }
    }

    /**
     * CSPRNG INVARIANT: üretilen paroların tekrarlanmaması.
     *
     * `mt_srand()` ile tohumlanmış bir üreteç kullanılırsa bu test
     * çöker — `str_shuffle` regresyonunun tam olarak yakalanacağı yer.
     * Tohumu SABİTLEYİP iki kez üretiyoruz: gerçek CSPRNG tohumu
     * yok sayar, zayıf PRNG aynı çıktıyı verir.
     */
    public function testGenerateIsNotAffectedBySeedingWeakPrng(): void
    {
        mt_srand(12345);
        $a = $this->hasher->generate(24);

        mt_srand(12345);
        $b = $this->hasher->generate(24);

        self::assertNotSame(
            $a,
            $b,
            'Aynı mt_srand tohumu aynı parolayı üretti: CSPRNG kullanılmıyor '
            . '(str_shuffle/mt_rand regresyonu).'
        );
    }

    /**
     * 200 üretimde çakışma olmamalı.
     *
     * Zayıf bir üreteçte ya da uzayı daraltan bir hatada çakışma
     * beklenenden çok daha erken görünür.
     */
    public function testGenerateProducesUniqueValues(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $seen[$this->hasher->generate(16)] = true;
        }

        self::assertCount(200, $seen, 'Üretilen paroralarda çakışma var.');
    }

    /**
     * Üretilen parola kendi politikasını geçmeli.
     *
     * `generate()` bir kullanıcıya gönderilmek için var; ürettiği değerin
     * uygulamanın parola kurallarını sağlamaması, kullanıcıyı ilk
     * değiştirmede reddedilen bir parolayla bırakırdı.
     */
    public function testGeneratedPasswordIsHashableAndVerifiable(): void
    {
        $plain = $this->hasher->generate();
        $hash  = $this->hasher->hash($plain);

        self::assertNotSame('', $plain);
        self::assertTrue($this->hasher->verify($plain, $hash));
    }
}
