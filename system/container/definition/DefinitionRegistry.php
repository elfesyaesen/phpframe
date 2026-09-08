<?php

declare(strict_types=1);

namespace System\Container\Definition;

use System\Container\Lifetime\Lifetime;

/**
 * id → Definition haritası.
 *
 * Deterministik olmak ZORUNDA: `sorted()` çıktısı derleme hash'ine ve
 * üretilen dosyanın byte sırasına girer. Aynı tanımlar aynı derlemeyi
 * üretmezse §31'in cache invalidation'ı ve CI'ın "recompile etmeyi unuttun"
 * kontrolü çalışmaz.
 */
final class DefinitionRegistry
{
    /** @var array<string, Definition> */
    private array $definitions = [];

    public function set(Definition $definition): void
    {
        $this->definitions[$definition->id] = $definition;
    }

    public function get(string $id): ?Definition
    {
        return $this->definitions[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->definitions[$id]);
    }

    public function remove(string $id): void
    {
        unset($this->definitions[$id]);
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * Kayıt sırasıyla tüm tanımlar.
     *
     * @return array<string, Definition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * id'ye göre sıralanmış tanımlar — derleme çıktısının byte-stabil olması
     * ve hash'in tekrarlanabilir olması için.
     *
     * @return array<string, Definition>
     */
    public function sorted(): array
    {
        $sorted = $this->definitions;
        ksort($sorted);

        return $sorted;
    }

    /**
     * @return array<string, Definition>
     */
    public function byLifetime(Lifetime $lifetime): array
    {
        return array_filter(
            $this->sorted(),
            static fn(Definition $d): bool => $d->lifetime === $lifetime
        );
    }

    /**
     * instance() ile verilen, derlenmiş container'a dışarıdan geçilmesi
     * gereken servis id'leri.
     *
     * @return list<string>
     */
    public function externalIds(): array
    {
        $ids = [];
        foreach ($this->sorted() as $id => $definition) {
            if ($definition->isExternal()) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Derlenemeyen (closure) factory'ler — FactoryValidator bunları hataya
     * çevirir, ama liste CLI tarafından da göç raporu olarak kullanılır.
     *
     * @return array<string, Definition>
     */
    public function closureFactories(): array
    {
        return array_filter(
            $this->sorted(),
            static fn(Definition $d): bool => $d->factory !== null && !$d->factory->isCompilable()
        );
    }

    /**
     * Bağımlılıklarını bildirmemiş factory'ler — kasıtlı opaklık meşrudur
     * (döngü kırma), ama sessiz kalmaması için raporlanır.
     *
     * @return array<string, Definition>
     */
    public function undeclaredFactories(): array
    {
        return array_filter(
            $this->sorted(),
            static fn(Definition $d): bool => $d->factory !== null && $d->dependsOn === []
        );
    }
}
