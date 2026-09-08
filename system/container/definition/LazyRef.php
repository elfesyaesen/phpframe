<?php

declare(strict_types=1);

namespace System\Container\Definition;

/**
 * Ertelenmiş servis referansı — BİLDİRİLMİŞ döngü-kesme kenarı.
 *
 * Neden `Closure` yerine bu tip var:
 *
 * bootstrap.php'de `DatabaseStorage(databaseProvider: fn() => $c->get(Database::class))`
 * gerçek bir döngüyü (Database → Recorder → Storage → Database) kırıyor ve
 * çalışıyor. Sorun, kenarı ARAÇLARDAN saklaması: container'ın döngü koruması
 * closure'ın içini görmez, dolayısıyla closure'ı kaldıran bir refactor
 * anlaşılır bir istisna değil sessiz sonsuz özyineleme alır.
 *
 * `LazyRef` aynı işi yapar ama `$id`'yi taşır: kenar tipli, adlandırılmış ve
 * `cutEdge()` bildirimi ile eşleştirilebilir hâle gelir. `container:validate`
 * artık "döngü şu bildirilmiş lazy kenar ile kırılmış" diyebilir — ve bildirim
 * kaldırıldığı hâlde döngü sürüyorsa hata verebilir.
 */
final readonly class LazyRef
{
    /**
     * @param class-string        $id
     * @param \Closure():mixed    $resolver
     */
    public function __construct(
        public string $id,
        private \Closure $resolver,
    ) {}

    /** Hedef servisi ilk çağrıda çözer (memoize etmez — container zaten eder). */
    public function __invoke(): mixed
    {
        return ($this->resolver)();
    }

    /** Okunabilirlik için alias: `$this->databaseProvider->resolve()`. */
    public function resolve(): mixed
    {
        return ($this->resolver)();
    }
}
