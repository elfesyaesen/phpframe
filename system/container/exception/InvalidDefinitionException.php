<?php

declare(strict_types=1);

namespace System\Container\Exception;

/**
 * Tanımın kendisi geçersiz — çözümlemeden önce reddedilir.
 */
final class InvalidDefinitionException extends ContainerException
{
    /**
     * Değer var_export edilemez (obje, resource, closure).
     *
     * Bu compile-time'da yakalanmak ZORUNDA: yakalanmazsa require edildiğinde
     * fatal veren PHP kodu üretilir.
     */
    public static function notExportable(string $id, string $what, string $type): self
    {
        return new self(
            self::render(
                'NOT_EXPORTABLE',
                "Değer derlenmiş koda gömülemez: {$what}",
                [$id, $type . ' tipinde değer'],
                'var_export edilemez',
                "var_export() yalnızca scalar, array, null ve enum case basabilir.\n"
                . "Obje/resource/closure için:\n"
                . "  • canlı obje ise → `instance()` kullan (external olarak verilir)\n"
                . "  • türetilebiliyorsa → `factory()` kullan (her kurulumda üretilir)"
            ),
            'NOT_EXPORTABLE',
            [$id],
            $id,
        );
    }

    /**
     * Parametre default'u derlenemiyor — PHP 8.1+ initializer'da `new` ve
     * sabit ifade kullanılabiliyor; bunlar var_export edilemez.
     */
    public static function unexportableDefault(string $id, string $param, string $reason): self
    {
        return new self(
            self::render(
                'UNEXPORTABLE_DEFAULT',
                'Constructor parametresinin default değeri derlenemez.',
                [$id, 'parametre $' . $param, $reason],
                'default gömülemez',
                "PHP 8.1+ default değerlerde `new` ifadesine izin verir, ama bu\n"
                . "değer derlenmiş dosyaya yazılamaz.\n"
                . "Çözüm: bağımlılığı açık bir servis yap ve tipini bildir."
            ),
            'UNEXPORTABLE_DEFAULT',
            [$id],
            $id,
        );
    }

    /** Tanımın concrete'i o id ile uyumsuz. */
    public static function typeMismatch(string $id, string $concrete): self
    {
        return new self(
            self::render(
                'TYPE_MISMATCH',
                "'{$concrete}' sınıfı '{$id}' tipini karşılamıyor.",
                [$id . ' → ' . $concrete],
                'uyumsuz tip',
                "Bind edilen somut sınıf, id olarak verilen interface/sınıfı\n"
                . "implement/extend etmiyor.\n"
                . "Çözüm: doğru somut sınıfı bind et, ya da id'yi düzelt."
            ),
            'TYPE_MISMATCH',
            [$id],
            $id,
        );
    }
}
