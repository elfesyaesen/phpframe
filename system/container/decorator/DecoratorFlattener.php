<?php

declare(strict_types=1);

namespace System\Container\Decorator;

use System\Container\Binding\BindingRegistry;
use System\Container\Definition\Definition;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Exception\BindingException;

/**
 * Dekoratör zincirlerini tanımlara uygular (DI-plan §21).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NE YAPAR:
 *
 * Girdi:
 *   CacheInterface → RedisCache        (tanım)
 *   decorate(CacheInterface, MetricsCache)
 *   decorate(CacheInterface, LoggingCache)
 *
 * Çıktı:
 *   CacheInterface@inner.0 → RedisCache      (orijinal, yeniden adlandırıldı)
 *   CacheInterface@inner.1 → MetricsCache    (DECORATED → @inner.0)
 *   CacheInterface         → LoggingCache    (DECORATED → @inner.1)
 *
 * Yani zincir DERLEME ZAMANINDA DÜZLEŞTİRİLİR. Runtime'da dekoratör
 * araması, zincir yürüyüşü veya proxy YOKTUR — yalnızca iç içe `new`.
 *
 * NEDEN İÇ KATMAN YENİDEN ADLANDIRILIYOR: dekoratör, sardığı servisin AYNI
 * arayüzünü ister. Yeniden adlandırma olmadan `LoggingCache`'in
 * `CacheInterface` bağımlılığı yine `LoggingCache`'e çözülür ve sonsuz
 * özyineleme olur. `@` ayırıcısı PHP sınıf adlarında geçemediği için dahili
 * id'lerin gerçek bir servisle çakışması imkânsızdır.
 *
 * LIFETIME: dekoratörler dış id'nin lifetime'ını DEVRALIR. Bir singleton'ı
 * transient bir dekoratörle sarmak, her çözümlemede yeni bir sarmalayıcı
 * üretip aynı çekirdeği paylaşmak demektir — neredeyse her zaman bir hata,
 * ve scope doğrulaması bunu yakalayamaz (her iki taraf da aynı id).
 * ─────────────────────────────────────────────────────────────────────────
 */
final class DecoratorFlattener
{
    /**
     * Zincirleri uygular. Registry boşsa hiçbir şey yapmaz (sıfır maliyet).
     *
     * İDEMPOTENT: `build()` ve `ContainerCompiler::fromBuilder()` ikisi de
     * çağırır (biri dev runtime, diğeri derleme için) ve aynı builder
     * üzerinde ikisi de koşabilir. İki kez uygulamak zincirin kendi içine
     * sarılmasına yol açardı — `@inner.0`'ın varlığı bunu engeller.
     */
    public static function apply(
        DefinitionRegistry $definitions,
        DecoratorRegistry $decorators,
        ?BindingRegistry $bindings = null,
    ): void {
        if ($decorators->isEmpty()) {
            return;
        }

        foreach ($decorators->all() as $id => $chain) {
            // Zaten uygulanmış: iç katman mevcut.
            if ($definitions->has(DecoratorRegistry::innerId($id, 0))) {
                continue;
            }

            self::applyChain($definitions, $bindings, $id, $chain);
        }
    }

    /**
     * @param list<array{class: string, source: ?string}> $chain
     */
    private static function applyChain(
        DefinitionRegistry $definitions,
        ?BindingRegistry $bindings,
        string $id,
        array $chain,
    ): void {
        // Dekore edilen id genellikle bir ARAYÜZDÜR (`Cache::class`) ve
        // `bind(Cache, RedisCache)` tanımı SOMUT sınıf altına yazar —
        // binding yalnızca yönlendirir. Dolayısıyla `$definitions->get($id)`
        // doğrudan null döner; önce alias zinciri çözülmek zorunda.
        $target = $bindings?->resolve($id) ?? $id;
        $original = $definitions->get($target) ?? $definitions->get($id);

        if ($original === null) {
            throw BindingException::decoratingUnknown(
                $id,
                array_map(static fn(array $layer): string => $layer['class'], $chain),
            );
        }

        // Binding KALDIRILIR: artık `Cache` doğrudan EN DIŞ dekoratöre
        // çözülmeli. Binding kalsaydı `Cache` → `RedisCache`'e yönlenir ve
        // o tanım @inner.0'a taşındığı için hiçbir şey bulunamazdı.
        //
        // Somut sınıfın kendi tanımı da kaldırılır: aynı sınıf hem @inner.0
        // hem kendi adıyla kayıtlı kalsaydı, `get(RedisCache::class)`
        // dekoratörsüz bir örnek döndürür ve zincir sessizce atlanabilirdi.
        if ($target !== $id) {
            $bindings?->remove($id);
            $definitions->remove($target);
        }

        // 1. Orijinal tanımı @inner.0'a taşı.
        $innerId = DecoratorRegistry::innerId($id, 0);

        $definitions->set(new Definition(
            id: $innerId,
            concrete: $original->concrete,
            lifetime: $original->lifetime,
            factory: $original->factory,
            arguments: $original->arguments,
            dependsOn: $original->dependsOn,
            lazy: $original->lazy,
            autowired: $original->autowired,
            source: $original->source,
        ));

        // 2. Her dekoratör bir üst katmanı sarar. SON katman dış id'yi alır.
        $lastIndex = count($chain) - 1;

        foreach ($chain as $index => $layer) {
            $wraps = DecoratorRegistry::innerId($id, $index);

            // Son katman dışa açık id'yi alır; aradakiler @inner.N+1.
            $layerId = $index === $lastIndex
                ? $id
                : DecoratorRegistry::innerId($id, $index + 1);

            $definitions->set(new Definition(
                id: $layerId,
                concrete: $layer['class'],
                // Lifetime dış id'den DEVRALINIR (docblock'taki gerekçe).
                lifetime: $original->lifetime,
                // Argümanlar autowire ile doldurulacak; DECORATED işareti
                // AutowireResolver tarafından, sarılan tipe karşılık gelen
                // parametreye yerleştirilir.
                arguments: [],
                dependsOn: [$wraps],
                autowired: false,
                source: $layer['source'],
            ));
        }
    }

    /**
     * Bir dekoratör katmanının sardığı iç id — AutowireResolver, sarılan
     * tipe karşılık gelen parametreyi bulmak için bunu kullanır.
     *
     * @param list<string> $dependsOn
     */
    public static function wrappedIdOf(Definition $definition): ?string
    {
        foreach ($definition->dependsOn as $dependency) {
            if (str_contains($dependency, '@inner.')) {
                return $dependency;
            }
        }

        return null;
    }

    /**
     * Dahili id'den dışa açık id'yi çıkarır (`Foo@inner.2` → `Foo`).
     */
    public static function publicIdOf(string $innerId): string
    {
        $position = strpos($innerId, '@inner.');

        return $position === false ? $innerId : substr($innerId, 0, $position);
    }
}
