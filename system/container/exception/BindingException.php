<?php

declare(strict_types=1);

namespace System\Container\Exception;

/**
 * Binding/tanım kaydıyla ilgili hatalar (DI-plan §11).
 */
final class BindingException extends ContainerException
{
    /** Autowire kapalı ve id için açık tanım yok. */
    public static function noDefinition(string $id, array $chain = []): self
    {
        $lines = self::chainWith($chain, $id);

        return new self(
            self::render(
                'NO_DEFINITION',
                "'{$id}' için tanım yok ve autowire kapalı.",
                $lines,
                'tanımsız',
                "Çözüm: bir provider'da açıkça kaydet:\n"
                . "  \$b->singleton({$id}::class);"
            ),
            'NO_DEFINITION',
            $lines,
            $id,
        );
    }

    /**
     * Sınıf örneklenebilir değil. Pratikte bu HER ZAMAN "interface bind
     * edilmemiş" hatasıdır — mesaj da buna göre yazılmıştır.
     */
    public static function notInstantiable(string $id, array $chain = []): self
    {
        $lines = self::chainWith($chain, $id);

        $kind = interface_exists($id) ? 'interface' : (
            enum_exists($id) ? 'enum' : (
                trait_exists($id) ? 'trait' : 'abstract sınıf'
            )
        );

        return new self(
            self::render(
                'NOT_INSTANTIABLE',
                "'{$id}' örneklenemez ({$kind}).",
                $lines,
                'somut tip belirsiz',
                $kind === 'interface'
                    ? "Bir interface'in hangi somut sınıfa karşılık geldiğini container bilemez.\n"
                      . "Çözüm: binding ekle (DI-plan §11):\n"
                      . "  \$b->singleton({$id}::class, SomeConcrete::class);"
                    : "Çözüm: somut bir alt sınıf bind et, ya da bir factory ver:\n"
                      . "  \$b->factory({$id}::class, [SomeProvider::class, 'create']);"
            ),
            'NOT_INSTANTIABLE',
            $lines,
            $id,
        );
    }

    /** Alias zinciri kendine dönüyor (A → B → A). */
    public static function aliasCycle(array $chain): self
    {
        return new self(
            self::render(
                'ALIAS_CYCLE',
                'Alias zinciri döngüsel.',
                $chain,
                'zincir buraya dönüyor',
                "İki binding karşılıklı olarak birbirini gösteriyor.\n"
                . "Çözüm: zincirin sonunda somut, bind edilmemiş bir sınıf olmalı."
            ),
            'ALIAS_CYCLE',
            $chain,
            $chain[0] ?? null,
        );
    }

    /**
     * `decorate()` bildirimi var ama sarılacak tanım yok (DI-plan §21).
     *
     * @param list<string> $decorators
     */
    public static function decoratingUnknown(string $id, array $decorators): self
    {
        return new self(
            self::render(
                'DECORATING_UNKNOWN',
                "Dekore edilecek servis tanımlı değil: '{$id}'",
                [$id, ...array_map(static fn(string $d): string => $d . ' (dekoratör)', $decorators)],
                'sarılacak tanım yok',
                "`decorate()` mevcut bir tanımı sarar; sarılacak şey yoksa\n"
                . "zincir kurulamaz.\n"
                . "Çözüm: önce servisi kaydet, sonra dekore et:\n"
                . "  \$b->singleton({$id}::class, SomeConcrete::class);\n"
                . "  \$b->decorate({$id}::class, " . ($decorators[0] ?? 'SomeDecorator') . "::class);\n"
                . "SIRA ÖNEMSİZ (tanımlar bildirimseldir), ama İKİSİ DE gerekli."
            ),
            'DECORATING_UNKNOWN',
            [$id],
            $id,
        );
    }

    /**
     * Dekoratör, sardığı tipi constructor'ında istemiyor (DI-plan §21).
     */
    public static function decoratorMissingInner(string $decorator, string $decoratedType): self
    {
        return new self(
            self::render(
                'DECORATOR_MISSING_INNER',
                "Dekoratör sardığı servisi constructor'ında istemiyor.",
                [$decorator, $decoratedType . ' (beklenen parametre tipi)'],
                'iç katman parametresi yok',
                "Bir dekoratör, sardığı servisi constructor'ından ALMAK\n"
                . "ZORUNDA — aksi halde iç katman hiç kurulmaz ve zincir\n"
                . "sessizce kopar (dekore etmek değil YER DEĞİŞTİRMEK olur).\n"
                . "Çözüm:\n"
                . "  public function __construct(private readonly {$decoratedType} \$inner) {}"
            ),
            'DECORATOR_MISSING_INNER',
            [$decorator],
            $decorator,
        );
    }

    /** Aynı id iki provider tarafından tanımlanmış (provides() çakışması). */
    public static function duplicateProvider(string $id, string $first, string $second): self
    {
        return new self(
            self::render(
                'DUPLICATE_DEFINITION',
                "'{$id}' iki provider tarafından tanımlanmış.",
                [$id . '  ← ' . $first, $id . '  ← ' . $second],
                'çakışma',
                "İki provider aynı servisi tanımlıyor; hangisinin kazandığı liste\n"
                . "sırasına bağlı olurdu — bu deterministik değildir (DI-plan §4).\n"
                . "Çözüm: tanımı tek bir provider'da bırak."
            ),
            'DUPLICATE_DEFINITION',
            [$id],
            $id,
        );
    }
}
