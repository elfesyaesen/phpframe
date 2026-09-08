<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Definition\ExportGuard;
use System\Container\Exception\InvalidDefinitionException;

/**
 * PHP kaynak kodu üretim yardımcısı: girinti, FQCN kaçışlama, literal export
 * ve metot adı üretimi.
 *
 * METOT ADI KARARI: `get_` + FQCN, `\` → `_`. Hash'li kısa adlardan (3 byte
 * tasarruf) çok daha değerli olan şey, stack trace ve profiler çıktısında
 * hangi servisin kurulduğunun okunabilmesidir. Yalnızca patolojik `A\B` vs
 * `A_B` çakışmasında kısa hash soneki eklenir.
 */
final class CodeWriter
{
    private const INDENT = '    ';

    /** @var array<string, string> id => metot adı */
    private array $methodNames = [];

    /** @var array<string, string> metot adı => id (çakışma tespiti) */
    private array $taken = [];

    /**
     * Bir servis id'sinin derlenmiş metot adı.
     *
     * Deterministik ve idempotent olmak zorunda: aynı id her zaman aynı adı
     * alır, yoksa üretilen dosya byte-stabil olmaz ve §31 hash'i işe yaramaz.
     */
    public function methodName(string $id): string
    {
        if (isset($this->methodNames[$id])) {
            return $this->methodNames[$id];
        }

        $base = 'get_' . preg_replace('/[^A-Za-z0-9_]/', '_', $id);
        $name = $base;

        // Çakışma: `A\B` ve `A_B` aynı adı üretir. Kısa hash soneki ekle.
        if (isset($this->taken[$name]) && $this->taken[$name] !== $id) {
            $name = $base . '_' . substr(hash('xxh128', $id), 0, 6);
        }

        $this->taken[$name] = $id;

        return $this->methodNames[$id] = $name;
    }

    /**
     * Hedef servisi kuran çağrı ifadesi.
     *
     * Derlenmiş container'da bir servisin bağımlılığına erişim, bir map
     * lookup'ı veya interface çözümlemesi DEĞİL, doğrudan metot çağrısıdır —
     * DI-plan §16'nın "reflection = 0, graph lookup = 0" hedefi budur.
     */
    public function serviceCall(string $id): string
    {
        return '$this->' . $this->methodName($id) . '()';
    }

    /**
     * FQCN'i tam nitelikli, baştan `\` ile yazar.
     *
     * Üretilen dosyada `use` deyimi YOK: her ad tam yazılır, böylece dosya
     * namespace bağlamından etkilenemez ve generator'ın import takibi
     * yapması gerekmez.
     */
    public function fqcn(string $class): string
    {
        return '\\' . ltrim($class, '\\');
    }

    /**
     * `::class` sabiti olarak yazar — store anahtarları için.
     *
     * Elle yazılmış string yerine `::class` kullanmak, sınıf yeniden
     * adlandırıldığında sessiz cache miss değil derleme hatası verir.
     */
    public function classConst(string $class): string
    {
        return $this->fqcn($class) . '::class';
    }

    /**
     * Servis id'sini dizi anahtarı olarak yazar.
     *
     * Sınıf/interface ise `::class`, değilse (literal id, örn. 'app.name')
     * tırnaklı string.
     */
    public function idKey(string $id): string
    {
        if (class_exists($id) || interface_exists($id) || enum_exists($id)) {
            return $this->classConst($id);
        }

        return $this->export($id);
    }

    /**
     * Değeri PHP literal'i olarak yazar.
     *
     * Gömülemeyen değerler BURADA reddedilir. Bu kontrolün compile-time'da
     * olması şart: atlanırsa require edildiğinde fatal veren kod üretilir.
     */
    public function export(mixed $value, string $context = 'değer'): string
    {
        $reason = ExportGuard::reject($value);

        if ($reason !== null) {
            throw InvalidDefinitionException::notExportable($context, $context, $reason);
        }

        if (is_array($value)) {
            return $this->exportArray($value, 0);
        }

        return var_export($value, true);
    }

    /**
     * Diziyi okunabilir, girintili biçimde yazar. `var_export`'un çıktısı
     * (özellikle iç içe dizilerde) neredeyse okunamaz; üretilen dosya elle
     * incelenebilir olmak zorunda (`container:compile --dry-run` iş akışı).
     */
    private function exportArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $pad = str_repeat(self::INDENT, $depth + 1);
        $closePad = str_repeat(self::INDENT, $depth);

        $parts = [];
        foreach ($value as $key => $item) {
            $rendered = is_array($item)
                ? $this->exportArray($item, $depth + 1)
                : var_export($item, true);

            $parts[] = $isList
                ? $pad . $rendered
                : $pad . var_export($key, true) . ' => ' . $rendered;
        }

        return "[\n" . implode(",\n", $parts) . ",\n" . $closePad . ']';
    }

    /** Satırları verilen seviyede girintiler. */
    public function indent(string $code, int $level): string
    {
        $pad = str_repeat(self::INDENT, $level);

        return implode("\n", array_map(
            static fn(string $line): string => $line === '' ? '' : $pad . $line,
            explode("\n", $code)
        ));
    }

    /** @return array<string, string> id => metot adı (SERVICES map'i için) */
    public function methodMap(): array
    {
        return $this->methodNames;
    }
}
