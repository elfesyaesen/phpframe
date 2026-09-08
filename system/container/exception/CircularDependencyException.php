<?php

declare(strict_types=1);

namespace System\Container\Exception;

/**
 * Döngüsel bağımlılık (DI-plan §18). Compile-time'da yakalanması hedeflenir;
 * runtime varyantı yalnızca derlenmemiş (dev) yolda görülür.
 */
final class CircularDependencyException extends ContainerException
{
    private const FIX =
        "Döngüyü kırmanın üç yolu:\n"
        . "  1. Ortak parçayı üçüncü bir servise çıkar (tercih edilen).\n"
        . "  2. Bir kenarı `lazyRef()` ile ertele ve `cutEdge()` ile BİLDİR:\n"
        . "       \$b->factory(StorageInterface::class, [MonitorProvider::class, 'storage']);\n"
        . "       \$b->cutEdge(StorageInterface::class, Database::class, 'monitor kendi izlediği DB\\'ye yazar');\n"
        . "     Bildirilmiş kenar compile hatası vermez; bildirilmemiş olan verir.\n"
        . "  3. Bağımlılığı constructor'dan çıkar ve metot parametresi yap.";

    /**
     * Dev runtime'da tespit edilen döngü (ham id zinciri).
     *
     * @param list<string> $chain Döngüyü kapatan zincir; son eleman ilk elemanla aynıdır
     */
    public static function runtime(array $chain): self
    {
        return new self(
            self::render(
                'CIRCULAR_DEPENDENCY',
                'Döngüsel bağımlılık tespit edildi.',
                $chain,
                'döngü burada kapanıyor',
                self::FIX
            ),
            'CIRCULAR_DEPENDENCY',
            $chain,
            $chain[0] ?? null,
        );
    }

    /**
     * Compile-time'da tespit edilen döngü — zincir satırları lifetime ve
     * kaynak konumu ile zenginleştirilmiş olarak gelir.
     *
     * @param list<string> $lines Biçimlenmiş zincir satırları
     * @param list<string> $ids   Ham id zinciri (programatik erişim için)
     */
    public static function compiled(array $lines, array $ids): self
    {
        return new self(
            self::render(
                'CIRCULAR_DEPENDENCY',
                'Döngüsel bağımlılık tespit edildi.',
                $lines,
                'döngü burada kapanıyor',
                self::FIX
            ),
            'CIRCULAR_DEPENDENCY',
            $ids,
            $ids[0] ?? null,
        );
    }
}
