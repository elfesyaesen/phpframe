<?php

declare(strict_types=1);

namespace System\Monitor\Instrumentation;

use System\Cache\CacheInterface;
use System\Monitor\Contracts\RecorderInterface;

/**
 * Hit/miss sayan `CacheInterface` decorator'ı.
 *
 * Sarma (decorator) tercih edildi, alt sınıflama değil: `CacheInterface`'in üç
 * uygulaması var (`RedisCache`, `ArrayCache`, `NullCache`) ve hangisinin aktif
 * olduğunu `CacheFactory` çalışma zamanında seçiyor (Redis erişilemezse
 * NullCache'e degrade). Decorator hepsini tek kodla kapsar ve `RedisCache`'in
 * stampede kilit mantığına hiç dokunmaz.
 *
 * Cache-first mimaride hit oranı en önemli sağlık göstergesidir: `remember()`
 * çağrılarının çoğu miss veriyorsa cache ya yanlış anahtarlanmış ya da
 * sessizce NullCache'e düşmüş demektir.
 */
final class RecordingCache implements CacheInterface
{
    public function __construct(
        private readonly CacheInterface $inner,
        private readonly RecorderInterface $recorder,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->inner->get($key, $default);

        // Sentinel yok: "saklanmış null" ile "miss" ayrımı sürücünün içindedir
        // ve dışarıdan görülemez. has() ile ikinci bir tur atmak, ölçüm uğruna
        // cache'e iki kat yük bindirmek olurdu. Bu yüzden default'a eşitlik
        // yaklaşık bir hit/miss göstergesi olarak kullanılır — ölçüm bir
        // sinyaldir, muhasebe değil.
        $this->recorder->recordCache($key, $value !== $default);

        return $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function has(string $key): bool
    {
        $exists = $this->inner->has($key);
        $this->recorder->recordCache($key, $exists);

        return $exists;
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    /**
     * `remember()` iç sürücüye DELEGE EDİLİR; ölçüm callback'i sararak yapılır.
     *
     * Burada `has()` + `get()` + `set()` ile yeniden kurmak cazip görünür ama
     * `RedisCache::remember()`'ın stampede korumasını (LOCK_TTL, LOCK_WAIT_US,
     * yeniden deneme döngüsü) tamamen devre dışı bırakırdı — yani monitor'ü
     * açmak, popüler bir anahtarın süresi dolduğunda tüm isteklerin aynı anda
     * veritabanına yığılmasına yol açardı. İzleme, izlediği sistemin
     * davranışını değiştirmemelidir.
     *
     * Callback'in çalışıp çalışmaması hit/miss'in KESİN göstergesidir —
     * `get()` içindeki yaklaşık ölçümün aksine burada tahmin yok.
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $executed = false;

        $value = $this->inner->remember(
            $key,
            $ttl,
            static function () use ($callback, &$executed): mixed {
                $executed = true;

                return $callback();
            }
        );

        $this->recorder->recordCache($key, !$executed);

        return $value;
    }

    public function flush(): bool
    {
        return $this->inner->flush();
    }
}
