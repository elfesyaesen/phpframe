<?php

declare(strict_types=1);

namespace System\Security;

use SensitiveParameter;

/**
 * Parola politikasının TEK kaynağı: hash'leme, doğrulama, yeniden-hash ve
 * güvenli parola üretimi.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN VAR — iki gerçek hata bu sınıfla kapanıyor:
 *
 * 1. ALGORİTMA ÇATIŞMASI. Parola hash'leme üç ayrı controller satırında,
 *    İKİ FARKLI sabitle yapılıyordu:
 *      UserController::register()       → PASSWORD_DEFAULT
 *      UserController::resetPassword()  → PASSWORD_BCRYPT
 *      UserController::changePassword() → PASSWORD_BCRYPT
 *    PHP 8.5'te `PASSWORD_DEFAULT` hâlâ bcrypt olduğu için bugün çalışıyor;
 *    varsayılan argon2id'ye taşındığı gün aynı sütunda iki farklı algoritma
 *    oluşur. Kod tabanında hiçbir yerde `password_needs_rehash()` yoktu,
 *    yani geçiş yolu da yoktu.
 *
 * 2. `str_shuffle()` KRİPTOGRAFİK DEĞİL. Eski `generatePassword()`
 *    karakterleri `random_int()` ile doğru seçip, sonucu `str_shuffle()` ile
 *    karıştırıyordu. `str_shuffle` mt_rand (Mt19937) tabanlıdır: tohumlanmış
 *    bir durumda ÇIKTISI DETERMİNİSTİKTİR (ölçüldü). Karakter kümesi güçlü
 *    kalıyordu ama permütasyon tahmin edilebilir hale geliyordu. Burada
 *    `random_int` tabanlı Fisher-Yates kullanılır.
 *
 * NEDEN "SERVICE" DEĞİL: yüzeyi durumsuz ve iş birlikçisiz dört saf
 * fonksiyondur. Container'a servis olarak girmesi bağımlılık grafiğine bir
 * kenar eklemekten başka bir şey kazandırmaz; `System\Security` altında bir
 * yardımcı olarak durur ve enjekte edilir.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class PasswordHasher
{
    /**
     * Tek algoritma politikası.
     *
     * `PASSWORD_DEFAULT` kasıtlı: PHP çekirdeği varsayılanı güçlendirdiğinde
     * politika kendiliğinden ilerler ve `needsRehash()` mevcut hash'leri
     * kademeli olarak taşır. Sabit bir algoritma seçmek bu yolu kapatırdı.
     */
    private const ALGORITHM = PASSWORD_DEFAULT;

    /** Üretilen geçici parolanın varsayılan uzunluğu. */
    private const GENERATED_LENGTH = 16;

    /**
     * Belirsiz karakterler (0/O, 1/l/I) KASTEN dışarıda: üretilen parola
     * e-posta ile gönderilip elle yazılıyor.
     */
    private const UPPER   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER   = 'abcdefghijkmnpqrstuvwxyz';
    private const DIGITS  = '23456789';
    private const SPECIAL = '!@#$%&*';

    public function hash(#[SensitiveParameter] string $plain): string
    {
        return password_hash($plain, self::ALGORITHM);
    }

    public function verify(#[SensitiveParameter] string $plain, string $hash): bool
    {
        // Boş hash'te `password_verify` false döner; yine de erken çıkış
        // yapılır ki silinmiş/eksik kayıtlarda boşuna KDF maliyeti ödenmesin.
        if ($hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * Hash mevcut politikayla yeniden üretilmeli mi?
     *
     * Kullanımı: parola doğrulandıktan HEMEN SONRA — o an düz metin elde
     * olduğu için tek yeniden-hash şansı budur.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM);
    }

    /**
     * Kriptografik olarak güvenli geçici parola.
     *
     * Her sınıftan (büyük/küçük/rakam/özel) en az bir karakter garanti edilir,
     * kalanı tüm alfabeden doldurulur, sonra TAMAMI `random_int` tabanlı
     * Fisher-Yates ile karıştırılır — böylece garanti edilen karakterlerin
     * baştaki konumu sızmaz.
     */
    public function generate(int $length = self::GENERATED_LENGTH): string
    {
        $classes = [self::UPPER, self::LOWER, self::DIGITS, self::SPECIAL];

        // Her sınıftan bir karakter sığmalı; aksi halde "her sınıftan en az
        // bir tane" garantisi sessizce bozulurdu.
        $length = max($length, count($classes));

        $characters = [];

        foreach ($classes as $class) {
            $characters[] = $class[random_int(0, strlen($class) - 1)];
        }

        $alphabet = self::UPPER . self::LOWER . self::DIGITS . self::SPECIAL;

        for ($i = count($classes); $i < $length; $i++) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Fisher-Yates — `str_shuffle()` DEĞİL (bkz. sınıf docblock'u §2).
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
