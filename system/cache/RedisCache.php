<?php

declare(strict_types=1);

namespace System\Cache;

use System\Logging\Contracts\LoggerInterface;

/**
 * phpredis (\Redis) tabanlı birincil cache sürücüsü.
 *
 * Tasarım ilkeleri:
 * - Lazy connect: bağlantı ilk cache işleminde kurulur (bootstrap'i yavaşlatmaz).
 * - Zarif degrade: Redis erişilemezse exception fırlatılmaz; işlemler "miss"
 *   gibi davranır (get->default, set->false). Böylece Redis kesintisi API'yi
 *   düşürmez, yalnızca DB yükü artar (cache-first sistemin güvenli modu).
 * - Değerler serialize edilir; null/false dahil her değer güvenle saklanır.
 */
final class RedisCache extends AbstractCache
{
    /** Stampede kilit TTL (saniye): üretici çökerse kilit otomatik serbest kalır. */
    private const LOCK_TTL = 5;
    /** Bekleyen istekler arası yeniden-okuma aralığı (mikrosaniye). */
    private const LOCK_WAIT_US = 50_000; // 50ms
    /** Maks. yeniden deneme (toplam ~1s), sonra fail-open. */
    private const LOCK_MAX_RETRIES = 20;

    private ?\Redis $redis = null;
    private bool $unavailable = false;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly string $password = '',
        private readonly int $database = 0,
        string $prefix = '',
        int $defaultTtl = 300,
        private readonly ?LoggerInterface $logger = null,
        private readonly float $timeout = 1.5,
        private readonly bool $persistent = false,
        /**
         * unserialize için izin verilen sınıflar. true = kısıtsız (mevcut davranış;
         * obje cache'ini bozmaz). false = yalnızca skaler/array (object-injection'a
         * karşı sertleştirme). Güvenilmeyen Redis paylaşımlarında false önerilir.
         */
        private readonly array|bool $unserializeAllowedClasses = true,
    ) {
        parent::__construct($prefix, $defaultTtl);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $redis = $this->connection();
        if ($redis === null) {
            return $default;
        }

        try {
            $raw = $redis->get($this->prefixed($key));
            // Miss: phpredis 'false' döner. Saklanan değer her zaman serialize'lı
            // bir string olduğu için '=== false' güvenle miss demektir.
            if ($raw === false) {
                return $default;
            }
            return unserialize($raw, ['allowed_classes' => $this->unserializeAllowedClasses]);
        } catch (\Throwable) {
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $redis = $this->connection();
        if ($redis === null) {
            return false;
        }

        try {
            $ttl = $this->normalizeTtl($ttl);
            $payload = serialize($value);
            $k = $this->prefixed($key);

            return $ttl > 0
                ? (bool) $redis->setex($k, $ttl, $payload)
                : (bool) $redis->set($k, $payload);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Stampede (thundering-herd) korumalı remember.
     *
     * Hot bir anahtar expire olduğunda, yüksek eşzamanlılıkta yüzlerce istek aynı
     * anda miss alıp DB'ye gider. Burada yalnızca bir istek `:lock` anahtarını
     * (SET NX EX) alıp değeri üretir; diğerleri kısa süre bekleyip yeniden okur.
     * Kilit alınamaz ve değer hâlâ yoksa fail-open: yine de üretilir (sonsuz
     * bekleme yok). Redis erişilemezse temel davranışa (doğrudan üret) düşülür.
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $miss = new \stdClass();

        $value = $this->get($key, $miss);
        if ($value !== $miss) {
            return $value;
        }

        $redis = $this->connection();
        if ($redis === null) {
            // Redis yok → stampede koruması anlamsız; üret ve dön (degrade).
            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        }

        // Rezerve lock namespace'i: kullanıcının gerçek bir "key:lock" anahtarıyla
        // çakışmasını önler (aksi halde stampede kilidi kullanıcı verisini del edebilir).
        $lockKey = $this->prefixed('__stampede_lock__:' . $key);

        try {
            $gotLock = (bool) $redis->set($lockKey, '1', ['nx', 'ex' => self::LOCK_TTL]);
        } catch (\Throwable) {
            $gotLock = true; // kilit kurulamadı → fail-open üret
        }

        if ($gotLock) {
            try {
                // Çift kontrol: kilidi alana kadar başka biri yazmış olabilir.
                $value = $this->get($key, $miss);
                if ($value !== $miss) {
                    return $value;
                }

                $value = $callback();
                $this->set($key, $value, $ttl);
                return $value;
            } finally {
                try {
                    $redis->del($lockKey);
                } catch (\Throwable) {
                    // yoksay — kilit TTL ile zaten serbest kalır
                }
            }
        }

        // Kilit başkasında: kısa süre bekleyip yeniden oku.
        for ($i = 0; $i < self::LOCK_MAX_RETRIES; $i++) {
            usleep(self::LOCK_WAIT_US);
            $value = $this->get($key, $miss);
            if ($value !== $miss) {
                return $value;
            }
        }

        // Hâlâ yok (üretici çökmüş olabilir) → fail-open.
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function has(string $key): bool
    {
        $redis = $this->connection();
        if ($redis === null) {
            return false;
        }

        try {
            return (bool) $redis->exists($this->prefixed($key));
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        $redis = $this->connection();
        if ($redis === null) {
            return true;
        }

        try {
            $redis->del($this->prefixed($key));
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function flush(): bool
    {
        $redis = $this->connection();
        if ($redis === null) {
            return true;
        }

        try {
            // Prefix yoksa tüm DB; varsa yalnızca bu namespace'i SCAN ile temizle
            // (FLUSHDB diğer kullanıcılara dokunmasın).
            if ($this->prefix === '') {
                return (bool) $redis->flushDB();
            }

            $iterator = null;
            $pattern = $this->prefix . ':*';
            $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);

            while (($keys = $redis->scan($iterator, $pattern, 500)) !== false) {
                if ($keys !== []) {
                    $redis->del($keys);
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Lazy bağlantı. Erişilemezse bir kez işaretler ve null döner (degrade).
     */
    private function connection(): ?\Redis
    {
        if ($this->unavailable) {
            return null;
        }
        if ($this->redis instanceof \Redis) {
            return $this->redis;
        }

        try {
            $redis = new \Redis();
            // Persistent bağlantı (pconnect): FPM worker ömrü boyunca bağlantı
            // yeniden kullanılır, request başına TCP el sıkışması maliyetini eler.
            // persistent_id ile prefix/db ayrımı korunur (havuzların karışmaması için).
            $connected = $this->persistent
                ? $redis->pconnect($this->host, $this->port, $this->timeout, 'phpframe:' . $this->database)
                : $redis->connect($this->host, $this->port, $this->timeout);

            if (!$connected) {
                throw new \RuntimeException('Redis connect başarısız');
            }
            if ($this->password !== '') {
                $redis->auth($this->password);
            }
            if ($this->database !== 0) {
                $redis->select($this->database);
            }

            return $this->redis = $redis;
        } catch (\Throwable $e) {
            $this->unavailable = true; // tekrar deneme; zarif degrade
            $this->logger?->warning('Redis kullanılamıyor; cache devre dışı, DB fallback aktif', [
                'host'  => $this->host,
                'port'  => $this->port,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
