<?php

declare(strict_types=1);

namespace System\Container\Decorator;

/**
 * Dekoratör zincirleri (DI-plan §21).
 *
 *   $b->decorate(CacheInterface::class, MetricsCache::class);
 *   $b->decorate(CacheInterface::class, LoggingCache::class);
 *
 * Sonuç (SON eklenen EN DIŞTA):
 *
 *   LoggingCache → MetricsCache → RedisCache
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NASIL ÇALIŞIR — İÇ KATMAN YENİDEN ADLANDIRMA:
 *
 * Bir dekoratör, sardığı servisin AYNI arayüzünü ister. Naif bir kurulum
 * sonsuz özyineleme üretir: `LoggingCache` `CacheInterface` ister,
 * container `CacheInterface` = `LoggingCache` der, o da `CacheInterface`
 * ister...
 *
 * Çözüm: orijinal tanım DAHİLİ bir id'ye taşınır ve dekoratör o id'yi alır:
 *
 *   CacheInterface                      → LoggingCache  (dışa açık id)
 *   CacheInterface@inner.1              → MetricsCache
 *   CacheInterface@inner.0              → RedisCache    (orijinal tanım)
 *
 * Böylece zincir derleme zamanında DÜZLEŞTİRİLİR; runtime'da dekoratör
 * araması, zincir yürüyüşü veya proxy yoktur — yalnızca iç içe `new`.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class DecoratorRegistry
{
    /**
     * id => dekoratör sınıfları (ekleme sırasıyla; son eklenen en dışta)
     *
     * @var array<string, list<array{class: string, source: ?string}>>
     */
    private array $decorators = [];

    public function add(string $id, string $decoratorClass, ?string $source = null): void
    {
        $this->decorators[$id][] = ['class' => $decoratorClass, 'source' => $source];
    }

    public function has(string $id): bool
    {
        return isset($this->decorators[$id]);
    }

    /**
     * @return list<array{class: string, source: ?string}>
     */
    public function chain(string $id): array
    {
        return $this->decorators[$id] ?? [];
    }

    /**
     * Bir zincir katmanının dahili id'si.
     *
     * `@` ayırıcısı kasıtlı: PHP sınıf adlarında geçemez, dolayısıyla
     * gerçek bir servis id'siyle çakışması imkânsız.
     */
    public static function innerId(string $id, int $level): string
    {
        return $id . '@inner.' . $level;
    }

    /**
     * @return array<string, list<array{class: string, source: ?string}>>
     */
    public function all(): array
    {
        $decorators = $this->decorators;
        ksort($decorators);

        return $decorators;
    }

    public function isEmpty(): bool
    {
        return $this->decorators === [];
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->decorators));
    }
}
