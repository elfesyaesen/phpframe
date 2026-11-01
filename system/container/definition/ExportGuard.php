<?php

declare(strict_types=1);

namespace System\Container\Definition;

use UnitEnum;

final class ExportGuard
{
    public static function isExportable(mixed $value): bool
    {
        return self::reject($value) === null;
    }

    /**
     * @param int $depth
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
            return is_finite($value) ? null : 'sonlu olmayan float (NAN/INF)';
        }

        if ($value instanceof UnitEnum) {
            return null;
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
