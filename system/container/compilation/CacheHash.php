<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Binding\BindingRegistry;
use System\Container\Core\Container;
use System\Container\Definition\Definition;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Resolution\ReflectionCache;

/**
 * Derleme kimliği ve bayatlık tespiti (DI-plan §31).
 *
 * Hash bir DEĞİŞİM DEDEKTÖRÜdür, güvenlik sınırı değil — bu yüzden xxh128
 * (hızlı, 16 hex karakter) yeterli ve doğru seçim.
 *
 * KRİTİK: hash tekrarlanabilir olmak ZORUNDA. Aynı kaynak aynı hash'i
 * üretmezse §32'nin CI kapısı ("recompile etmeyi unuttun mu?") her koşuda
 * yalancı pozitif verir ve devre dışı bırakılır. Bu yüzden:
 *   • tanımlar kanonikleştirilir ve sıralanır
 *   • kaynak parmak izi CI'da sha1_file, dev'de mtime+size kullanır
 *     (mtime taze bir git checkout'ta reprodüktif DEĞİLDİR)
 */
final class CacheHash
{
    private const ALGO = 'xxh128';

    /**
     * @param array<string, string> $configFiles etiket => dosya yolu
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly array $configFiles = [],
        private readonly bool $useContentHash = false,
    ) {}

    /**
     * @param array<string, mixed> $extra Ek girdiler (env anahtarları vb.)
     */
    public function compute(
        DefinitionRegistry $definitions,
        BindingRegistry $bindings,
        array $extra = [],
        ?ReflectionCache $reflection = null,
    ): string {
        $payload = [
            'framework' => Container::VERSION,
            // Patch sürümü semantiği değiştirmez; onu hash'e katmak her PHP
            // güncellemesinde gereksiz recompile zorlar.
            'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'config' => $this->configFingerprint(),
            'definitions' => $this->definitionFingerprint($definitions),
            'bindings' => $this->bindingFingerprint($bindings),
            'sources' => $this->sourceFingerprint($definitions, $reflection),
            'extra' => $this->canonicalize($extra),
        ];

        return hash(self::ALGO, serialize($payload));
    }

    /**
     * Tanımların kanonik parmak izi.
     *
     * Definition objelerini doğrudan serialize ETMEK YANLIŞ olurdu: Closure
     * içeren FactoryRef serialize edilemez, ve obje yapısındaki ilgisiz bir
     * değişiklik (yeni bir alan eklemek) tüm hash'leri geçersiz kılar.
     * Yalnızca DAVRANIŞI belirleyen alanlar alınır.
     *
     * @return array<string, mixed>
     */
    private function definitionFingerprint(DefinitionRegistry $definitions): array
    {
        $out = [];

        foreach ($definitions->sorted() as $id => $definition) {
            $out[$id] = [
                'concrete' => $definition->concrete,
                'lifetime' => $definition->lifetime->value,
                'factory' => $definition->factory === null ? null : [
                    'kind' => $definition->factory->kind->value,
                    'class' => $definition->factory->class,
                    'method' => $definition->factory->method,
                ],
                'arguments' => array_map(
                    static fn(\System\Container\Definition\ArgumentRef $a): array => [
                        'kind' => $a->kind->name,
                        'id' => $a->id,
                        'parameter' => $a->parameter,
                        // Literal değer hash'e girer: bir default değişirse
                        // derleme bayatlar.
                        'value' => self::stringifyValue($a->value),
                    ],
                    $definition->arguments
                ),
                'dependsOn' => $definition->dependsOn,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function bindingFingerprint(BindingRegistry $bindings): array
    {
        $out = [];

        foreach ($bindings->sorted() as $id => $binding) {
            $out[$id] = $binding->target;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function configFingerprint(): array
    {
        $out = [];

        foreach ($this->configFiles as $label => $path) {
            $out[$label] = is_file($path)
                ? hash_file(self::ALGO, $path)
                : 'missing';
        }

        ksort($out);

        return $out;
    }

    /**
     * Derlenen servisleri barındıran dosyaların parmak izi.
     *
     * Bir sınıfın constructor'ı değiştiğinde tanımlar aynı kalabilir (arguments
     * lazy doldurulduğu için) ama derlenmiş kod yanlış olur. Bu yüzden kaynak
     * dosyalar da hash'e girer.
     *
     * useContentHash: CI'da true olmalı — mtime taze checkout'ta rastgeledir,
     * dolayısıyla CI her koşuda farklı hash üretir ve karşılaştırma anlamsızlaşır.
     *
     * @return array<string, string>
     */
    private function sourceFingerprint(DefinitionRegistry $definitions, ?ReflectionCache $reflection): array
    {
        $reflection ??= new ReflectionCache();

        $files = [];

        foreach ($definitions->sorted() as $definition) {
            foreach ($this->classesOf($definition) as $class) {
                $file = $reflection->fileName($class);
                if ($file === null) {
                    continue;
                }

                $relative = $this->relative($file);
                if (isset($files[$relative])) {
                    continue;
                }

                $files[$relative] = $this->useContentHash
                    ? (hash_file(self::ALGO, $file) ?: 'unreadable')
                    : (string) @filemtime($file) . ':' . (string) @filesize($file);
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function classesOf(Definition $definition): array
    {
        $classes = [$definition->concrete];

        if ($definition->factory?->class !== null) {
            $classes[] = $definition->factory->class;
        }

        return array_values(array_filter(
            array_unique($classes),
            static fn(string $c): bool => class_exists($c) || interface_exists($c)
        ));
    }

    private function relative(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', $this->appRoot);

        return str_starts_with($normalized, $root)
            ? ltrim(substr($normalized, strlen($root)), '/')
            : $normalized;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function canonicalize(array $extra): array
    {
        ksort($extra);

        return array_map(
            static fn(mixed $v): mixed => is_array($v) ? self::sortRecursive($v) : self::stringifyValue($v),
            $extra
        );
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(
            static fn(mixed $v): mixed => is_array($v) ? self::sortRecursive($v) : self::stringifyValue($v),
            $value
        );
    }

    /**
     * Değeri hash'lenebilir bir temsile çevirir.
     *
     * Obje/closure serialize edilemez; onlar tanım kimliğinin parçası değil
     * (external olarak dışarıdan gelirler), o yüzden yalnızca tip adı alınır.
     */
    private static function stringifyValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return self::sortRecursive($value);
        }

        if ($value instanceof \UnitEnum) {
            return $value::class . '::' . $value->name;
        }

        return '<' . get_debug_type($value) . '>';
    }
}
