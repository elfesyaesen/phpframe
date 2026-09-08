<?php

declare(strict_types=1);

namespace System\Helpers;

/**
 * UUID doğrulama yardımcısı — UUID biçim kontrolü için tek doğruluk kaynağı.
 *
 * Daha önce aynı regex birden çok controller'da (Widget/User/Device/Ring)
 * tekrarlanıyordu; tek noktadan yönetilir. Hem imperatif kontrol (isValid)
 * hem de validation kuralı (Rules\Uuid) bu deseni kullanır.
 */
final class Uuid
{
    /**
     * Standart 8-4-4-4-12 hex UUID deseni (case-insensitive).
     */
    public const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * RFC 4122 sürüm-4 (rastgele) UUID üretir.
     *
     * UUID birincil anahtarlar uygulama tarafında burada üretilir; veritabanına
     * özgü bir varsayılana (PostgreSQL gen_random_uuid() gibi) bağımlılık
     * olmadan tüm sürücülerde (MySQL dahil) çalışır.
     */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // sürüm 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // varyant 10xx

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
