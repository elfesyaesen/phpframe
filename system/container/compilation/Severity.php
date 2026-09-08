<?php

declare(strict_types=1);

namespace System\Container\Compilation;

/**
 * Diagnostic önem derecesi.
 *
 * Sınır net: ERROR derlemeyi bloklar, diğerleri bloklamaz. "Bazen bloklayan"
 * bir seviye yoktur — CI kapısının deterministik olması buna bağlı.
 */
enum Severity: string
{
    /** Derleme başarısız. Üretilen container yanlış olurdu. */
    case ERROR = 'error';

    /**
     * Derleme sürer ama muhtemelen istenmeyen bir durum var.
     * Örnek: SINGLETON → TRANSIENT (transient fiilen singleton olur).
     */
    case WARNING = 'warning';

    /**
     * Bilgi. Kasıtlı olabilecek ama SESSİZ KALMAMASI gereken durumlar.
     * Örnek: bağımlılıklarını bildirmemiş factory (opaklık meşru ama görünür olmalı).
     */
    case NOTICE = 'notice';

    public function rank(): int
    {
        return match ($this) {
            self::ERROR   => 2,
            self::WARNING => 1,
            self::NOTICE  => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ERROR   => 'HATA',
            self::WARNING => 'UYARI',
            self::NOTICE  => 'BİLGİ',
        };
    }
}
