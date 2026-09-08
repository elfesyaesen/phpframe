<?php

declare(strict_types=1);

namespace System\Cache;

/**
 * Uygulama seviyesi cache sözleşmesi (cache-first mimarinin temeli).
 *
 * Tüm sürücüler (Redis, Array, Null) bu arayüzü uygular. Değerler sürücü
 * tarafında serialize edilir; bu sayede null/false gibi değerler de güvenle
 * saklanır ve "miss" ile "saklanmış null" birbirinden ayrılır.
 */
interface CacheInterface
{
    /**
     * Anahtarın değerini döner; yoksa $default.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Değeri TTL (saniye) ile saklar. $ttl null ise varsayılan TTL kullanılır.
     * $ttl <= 0 ise süresiz saklanır (sürücü destekliyorsa).
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Anahtar mevcut (ve süresi dolmamış) mı?
     */
    public function has(string $key): bool;

    /**
     * Anahtarı siler. Yoksa da true döner (idempotent).
     */
    public function delete(string $key): bool;

    /**
     * Cache-first yardımcı: değer cache'te varsa onu döner; yoksa $callback
     * çalıştırılır, sonucu saklanır ve döndürülür.
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed;

    /**
     * Bu cache namespace'indeki (prefix'li) tüm anahtarları temizler.
     */
    public function flush(): bool;
}
