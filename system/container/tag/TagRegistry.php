<?php

declare(strict_types=1);

namespace System\Container\Tag;

/**
 * Servis etiketleri (DI-plan §20).
 *
 *   $b->tag([AuthMiddleware::class, RateLimitMiddleware::class], 'http.middleware');
 *
 * Etiketli bir liste `#[Tagged('http.middleware')] iterable $middleware` ile
 * enjekte edilir ve DERLEME ZAMANINDA sabit bir dizi ifadesine çevrilir —
 * runtime'da etiket sorgusu YOKTUR.
 *
 * SIRA GARANTİSİ: üyeler EKLENME SIRASINI korur, alfabetik sıralanmaz.
 * Bu, etiketlerin en yaygın kullanımı olan middleware/pipeline zincirleri
 * için zorunlu — `rate_limit` `api_auth`'tan önce çalışmalı ve bu sıra
 * alfabetik değil anlamsaldır. Sıra deterministiktir çünkü provider listesi
 * ve `tag()` çağrıları deterministiktir.
 */
final class TagRegistry
{
    /**
     * tag => servis id'leri (ekleme sırasıyla)
     *
     * @var array<string, list<string>>
     */
    private array $tags = [];

    /**
     * @param list<string> $ids
     */
    public function add(string $tag, array $ids): void
    {
        foreach ($ids as $id) {
            // Aynı servisi aynı etikete iki kez eklemek sessizce yok
            // sayılır: iki provider aynı middleware'i etiketlerse pipeline
            // onu iki kez çalıştırmamalı.
            if (!in_array($id, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $id;
            }
        }
    }

    /**
     * Etiketin üyeleri, EKLENME SIRASIYLA.
     *
     * @return list<string>
     */
    public function members(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    public function has(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->tags);
        sort($names);

        return $names;
    }

    /**
     * Bir servisin taşıdığı etiketler (`container:debug` için).
     *
     * @return list<string>
     */
    public function tagsOf(string $id): array
    {
        $out = [];

        foreach ($this->tags as $tag => $ids) {
            if (in_array($id, $ids, true)) {
                $out[] = $tag;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Tüm etiketler — hash ve CLI için deterministik sıra (etiket adına göre,
     * üye sırası korunur).
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $tags = $this->tags;
        ksort($tags);

        return $tags;
    }

    public function isEmpty(): bool
    {
        return $this->tags === [];
    }

    public function count(): int
    {
        return count($this->tags);
    }
}
