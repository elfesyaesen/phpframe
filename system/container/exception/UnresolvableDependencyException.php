<?php

declare(strict_types=1);

namespace System\Container\Exception;

/**
 * Constructor parametresi çözülemedi (DI-plan §9, §10).
 *
 * Önemli tasarım kararı: nullable-default'suz bir parametre SESSİZCE null
 * OLMAZ. `?LoggerInterface $l` için null enjekte etmek, sessizce loglamayan
 * bir servis üretir — bu, gürültülü bir build hatasından çok daha kötüdür.
 */
final class UnresolvableDependencyException extends ContainerException
{
    /** Builtin/tipsiz parametre, default değeri de yok. */
    public static function primitive(string $id, string $param, ?string $type, array $chain = []): self
    {
        $lines = self::chainWith(
            $chain,
            $id,
            'parametre $' . $param . ($type !== null ? ' (' . $type . ')' : ' (tipsiz)')
        );

        return new self(
            self::render(
                'UNRESOLVABLE_DEPENDENCY',
                'Constructor parametresi çözülemedi.',
                $lines,
                'default yok, binding yok',
                "Primitive injection desteklenmez (DI-plan §10).\n"
                . "Çözüm 1 (tercih edilen) — typed config objesi:\n"
                . "  final readonly class SomeConfig { public function __construct(public string \${$param}) {} }\n"
                . "  // sonra: __construct(SomeConfig \$config)\n"
                . "Çözüm 2 — literal bind et (yalnızca var_export güvenli değerler):\n"
                . "  \$b->literal('some.key', 'value');"
            ),
            'UNRESOLVABLE_DEPENDENCY',
            $lines,
            $id,
        );
    }

    /**
     * Union/intersection tip: tanımı olan üye sayısı 1 değil.
     *
     * @param list<string> $candidates
     */
    public static function ambiguousType(string $id, string $param, array $candidates, array $chain = []): self
    {
        $lines = self::chainWith($chain, $id, 'parametre $' . $param);

        $claim = $candidates === []
            ? 'Union/intersection tipin hiçbir üyesi bind edilmemiş.'
            : 'Union/intersection tipin birden fazla üyesi bind edilmiş.';

        $fix = $candidates === []
            ? "Çözüm: üyelerden BİRİNİ bind et, ya da parametreyi tek somut tipe daralt."
            : "Adaylar: " . implode(', ', $candidates) . "\n"
              . "Container hangisini seçeceğini bilemez.\n"
              . "Çözüm: parametreyi tek tipe daralt, ya da bir factory ile açıkça ver.";

        return new self(
            self::render('UNRESOLVABLE_DEPENDENCY', $claim, $lines, 'belirsiz tip', $fix),
            'UNRESOLVABLE_DEPENDENCY',
            $lines,
            $id,
        );
    }

    /**
     * `#[Tagged('x')]` ama `x` etiketi hiç kaydedilmemiş.
     *
     * Boş dizi enjekte etmek YASAK: bir middleware zincirinin veya listener
     * listesinin sessizce boş kalması "neden hiçbir şey çalışmıyor?" sınıfı
     * bir hatadır ve etiket adındaki bir yazım hatası aylarca fark edilmez.
     *
     * @param list<string> $available
     */
    public static function unknownTag(string $id, string $param, string $tag, array $available): self
    {
        $lines = self::chainWith([], $id, 'parametre $' . $param . "  #[Tagged('{$tag}')]");

        $fix = "Etiket hiç kaydedilmemiş. Bir provider'da tanımla:\n"
            . "  \$b->tag([SomeService::class, OtherService::class], '{$tag}');\n"
            . "veya sınıfların kendisini işaretle:\n"
            . "  #[Tagged('{$tag}')]\n"
            . "  final class SomeService {}";

        if ($available !== []) {
            $fix .= "\n\nKayıtlı etiketler: " . implode(', ', $available);
        }

        return new self(
            self::render(
                'UNKNOWN_TAG',
                "Bilinmeyen etiket: '{$tag}'",
                $lines,
                'etiket yok',
                $fix
            ),
            'UNKNOWN_TAG',
            $lines,
            $id,
        );
    }

    /**
     * `#[Named('x')]` ama parametrenin sınıf tipi yok.
     *
     * `Named` tip + ad BİRLEŞİMİNİ arar (`Connection@primary`); tip yoksa
     * arayacak bir şey de yoktur.
     */
    public static function namedWithoutType(string $id, string $param, string $name): self
    {
        $lines = self::chainWith([], $id, 'parametre $' . $param . "  #[Named('{$name}')]");

        return new self(
            self::render(
                'NAMED_WITHOUT_TYPE',
                "#[Named] bir sınıf tipi gerektirir.",
                $lines,
                'tip yok',
                "#[Named] servis id'sini TİP + AD birleşiminden kurar\n"
                . "  (örn. Connection@{$name}), dolayısıyla parametrenin sınıf\n"
                . "  tipi olmak zorunda.\n"
                . "Çözüm: parametreye sınıf/arayüz tipi ver, ya da sabit bir\n"
                . "değer için #[Config('anahtar')] kullan."
            ),
            'NAMED_WITHOUT_TYPE',
            $lines,
            $id,
        );
    }
}
