<?php

declare(strict_types=1);

namespace System\Validation\Rules;

/**
 * Veritabanı Benzersizlik Kuralı
 *
 * Kullanım:
 * - 'unique:users,email' → users tablosunda email sütunu benzersiz olmalı
 * - 'unique:users,email,5' → id=5 olan kayıt hariç
 * - 'unique:users,email,5,user_id' → user_id=5 olan kayıt hariç
 *
 * Ortak mantık AbstractUniqueRule'da; bu kural ek WHERE koşulu eklemez.
 */
final class Unique extends AbstractUniqueRule
{
    /**
     * Factory method
     */
    public static function make(string $table, string $column, ?int $ignoreId = null): self
    {
        $instance = new self();
        $instance->setParameters(
            $ignoreId !== null ? [$table, $column, (string) $ignoreId] : [$table, $column]
        );
        return $instance;
    }
}
