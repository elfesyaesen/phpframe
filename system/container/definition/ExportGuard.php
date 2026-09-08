<?php

declare(strict_types=1);

namespace System\Container\Definition;

use UnitEnum;

/**
 * Bir değerin derlenmiş PHP dosyasına gömülebilir olup olmadığını belirler.
 *
 * NEDEN AYRI BİR SINIF: bu kontrol compile-time'da yapılmak ZORUNDA. Atlanırsa
 * `var_export()` bir obje için `\Foo::__set_state([...])` basar ya da uyarı
 * verip yanlış çıktı üretir; sonuç, require edildiğinde fatal veren bir
 * container dosyası olur — yani hata, üretilen kodun çalıştırıldığı ana kadar
 * gizlenir. Burada erken reddetmek, o hatayı derleme çıktısına taşır.
 *
 * Gömülebilir: null, bool, int, float, string, enum case, ve bunlardan oluşan
 * (özyinelemeli) dizi. Gömülemez: obje, resource, closure.
 */
final class ExportGuard
{
    /** Değer var_export ile güvenle basılabilir mi? */
    public static function isExportable(mixed $value): bool
    {
        return self::reject($value) === null;
    }

    /**
     * Değer gömülemiyorsa insan-okunur sebebi, gömülebiliyorsa null döndürür.
     *
     * @param int $depth Özyineleme derinliği koruması (kendine referans veren
     *                   dizi var_export'u da patlatır)
     */
    public static function reject(mixed $value, int $depth = 0): ?string
    {
        if ($depth > 32) {
            return 'dizi çok derin (32+) veya kendine referans veriyor';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return null;
        }

        if (is_float($value)) {
            // NAN/INF var_export'ta geçerli PHP üretmez.
            return is_finite($value) ? null : 'sonlu olmayan float (NAN/INF)';
        }

        if ($value instanceof UnitEnum) {
            return null; // var_export enum case'i \Foo::Bar olarak basar
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $reason = self::reject($item, $depth + 1);
                if ($reason !== null) {
                    return 'dizi içinde: ' . $reason;
                }
            }
            return null;
        }

        if (is_object($value)) {
            return $value::class . ' objesi (obje gömülemez — instance() veya factory() kullan)';
        }

        return get_debug_type($value) . ' gömülemez';
    }
}
