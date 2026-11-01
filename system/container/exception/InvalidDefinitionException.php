<?php

declare(strict_types=1);

namespace System\Container\Exception;

final class InvalidDefinitionException extends ContainerException
{
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
