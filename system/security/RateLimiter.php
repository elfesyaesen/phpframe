<?php

declare(strict_types=1);

namespace System\Security;

use System\Config\CacheConfig;

/**
 * Rate Limiter
 *
 * Birincil depolama: Redis (atomik INCR — eşzamanlılıkta doğru sayım, cluster-global
 * limit). Redis erişilemez / yapılandırılmamışsa zarif degrade ile dosya tabanlı
 * depolamaya düşer (node-local). IP bazlı veya custom key ile istek sınırlaması yapar.
 *
 * Dosya fallback'i, oku-artır-yaz döngüsünü tek bir flock(LOCK_EX) altında yaparak
 * eşzamanlılıkta doğru sayar (undercount yok). Redis çalışma anında düşerse limit
 * sessizce devre dışı kalmaz — dosya fallback'ine geçilir.
 */
final class RateLimiter
{
    /**
     * Cache dizini (dosya fallback'i için)
     */
    private string $cacheDir;

    /**
     * Varsayılan limit değerleri
     */
    private int $maxAttempts = 60;
    private int $decaySeconds = 60;

    /**
     * Lazy Redis bağlantısı. null = henüz denenmedi veya kullanılamıyor.
     * RedisCache ile aynı graceful-degrade ilkesini izler (atomik sayaç için
     * raw \Redis gerekir; cache arayüzü INCR sunmaz, bu yüzden ayrı bağlantı).
     */
    private ?\Redis $redis = null;
    private bool $redisChecked = false;

    /**
     * @param CacheConfig|null $cache Cache sürücüsü ve Redis ayarları.
     *        Eskiden bu sınıf altı ayrı `defined('CACHE_*'|'REDIS_*')`
     *        kontrolü yapıyordu; her biri "config yüklendi mi" sorusunu
     *        yeniden soruyor ve yüklenmemişse SESSİZCE farklı bir sürücüye
     *        (dosya) düşüyordu. Rate limit bir güvenlik mekanizması olduğu
     *        için hangi backend'in kullanıldığı belirsiz kalmamalı.
     */
    public function __construct(?string $cacheDir = null, private readonly ?CacheConfig $cache = null)
    {
        $this->cacheDir = $cacheDir ?? (defined('APP_ROOT') ? APP_ROOT . '/system/cache/rate' : sys_get_temp_dir() . '/rate');

        // Cache dizinini oluştur (yalnızca dosya fallback'i için gerekir)
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * İstek denemesi yap
     *
     * @param string $key Unique identifier (örn: IP adresi, user_id)
     * @param int $maxAttempts Maksimum deneme sayısı
     * @param int $decaySeconds Süre (saniye)
     * @return bool true = izin verildi, false = limit aşıldı
     */
    public function attempt(string $key, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        $redis = $this->redis();
        if ($redis !== null) {
            $result = $this->attemptRedis($redis, $key, $maxAttempts, $decaySeconds);
            if ($result !== null) {
                return $result;
            }
            // Redis çalışma anında düştü (attemptRedis null döndü) → blanket "izin ver"
            // YERİNE dosya fallback'ine geç; limit node-local olarak korunmaya devam eder.
        }

        return $this->attemptFile($key, $maxAttempts, $decaySeconds);
    }

    /**
     * Atomik Redis denemesi: SET NX EX ile (yoksa) TTL'li oluştur, sonra INCR.
     * İki eşzamanlı ilk-istek bile doğru sayılır ve anahtar daima TTL alır.
     *
     * @return bool|null true/false = karar; null = Redis çalışma anında düştü,
     *                   çağıran dosya fallback'ine geçmeli (fail-open DEĞİL).
     */
    private function attemptRedis(\Redis $redis, string $key, int $maxAttempts, int $decaySeconds): ?bool
    {
        try {
            $k = $this->redisKey($key);
            $redis->set($k, 0, ['nx', 'ex' => $decaySeconds]);
            $count = (int) $redis->incr($k);
            return $count <= $maxAttempts;
        } catch (\Throwable) {
            $this->redis = null; // bu request boyunca bir daha Redis deneme
            return null;
        }
    }

    /**
     * Dosya tabanlı atomik deneme: oku-artır-yaz döngüsü tek flock(LOCK_EX)
     * altında yapılır; iki eşzamanlı istek birbirinin artışını ezmez (race yok).
     * Sayım Redis path'iyle aynı semantiktir: önce artır, sonra <= max karşılaştır.
     */
    private function attemptFile(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $file = $this->getFilePath($key);

        // 'c+' : yoksa oluştur, varsa truncate ETMEDEN aç (mevcut sayaç korunur).
        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            // Dosya sistemi gerçekten bozuksa (disk dolu/izin) tüm siteyi 500'e
            // düşürmemek için availability lehine izin ver. Normal işleyişte buraya girilmez.
            return true;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return true;
            }

            $raw = stream_get_contents($fh);
            $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;

            $now = time();
            if (!is_array($data) || !isset($data['expires_at']) || $data['expires_at'] <= $now) {
                $data = ['attempts' => 1, 'expires_at' => $now + $decaySeconds];
            } else {
                $data['attempts']++;
            }

            $content = json_encode($data, JSON_THROW_ON_ERROR);
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, $content);
            fflush($fh);

            return $data['attempts'] <= $maxAttempts;
        } catch (\Throwable) {
            return true; // beklenmedik hata: availability lehine
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Limit aşıldı mı?
     */
    public function tooManyAttempts(string $key, int $maxAttempts = 60): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

    /**
     * Mevcut deneme sayısı
     */
    public function attempts(string $key): int
    {
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $value = $redis->get($this->redisKey($key));
                return $value === false ? 0 : (int) $value;
            } catch (\Throwable) {
                return 0;
            }
        }

        $data = $this->getData($key);

        if ($data === null || $this->isExpired($data)) {
            return 0;
        }

        return $data['attempts'];
    }

    /**
     * Kalan deneme hakkı
     */
    public function remaining(string $key, int $maxAttempts = 60): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    /**
     * Limitin sıfırlanmasına kalan süre (saniye)
     */
    public function availableIn(string $key): int
    {
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $ttl = $redis->ttl($this->redisKey($key));
                return $ttl > 0 ? $ttl : 0;
            } catch (\Throwable) {
                return 0;
            }
        }

        $data = $this->getData($key);

        if ($data === null || $this->isExpired($data)) {
            return 0;
        }

        return max(0, $data['expires_at'] - time());
    }

    /**
     * Retry-After header değeri
     */
    public function retryAfter(string $key): int
    {
        return $this->availableIn($key);
    }

    /**
     * Deneme sayısını artır
     */
    public function hit(string $key, int $decaySeconds = 60): int
    {
        $data = $this->getData($key);

        if ($data === null || $this->isExpired($data)) {
            $data = [
                'attempts' => 1,
                'expires_at' => time() + $decaySeconds,
            ];
        } else {
            $data['attempts']++;
        }

        $this->setData($key, $data);

        return $data['attempts'];
    }

    /**
     * Key için verileri temizle
     */
    public function clear(string $key): void
    {
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $redis->del($this->redisKey($key));
            } catch (\Throwable) {
                // yoksay
            }
            return;
        }

        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Tüm rate limit verilerini temizle
     */
    public function clearAll(): void
    {
        $files = glob($this->cacheDir . '/*.rate');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Expired cache dosyalarını temizle (garbage collection)
     */
    public function gc(): int
    {
        $deleted = 0;
        $files = glob($this->cacheDir . '/*.rate');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);

            if ($data === null || $this->isExpired($data)) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Rate limit bilgilerini al (API response için)
     *
     * @return array{limit: int, remaining: int, reset: int}
     */
    public function getHeaders(string $key, int $maxAttempts = 60): array
    {
        return [
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $this->remaining($key, $maxAttempts),
            'X-RateLimit-Reset' => time() + $this->availableIn($key),
        ];
    }

    /**
     * Key için veriyi oku
     *
     * @return array{attempts: int, expires_at: int}|null
     */
    private function getData(string $key): ?array
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        if ($data === null || !is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Key için veriyi yaz
     *
     * @param array{attempts: int, expires_at: int} $data
     */
    private function setData(string $key, array $data): void
    {
        $file = $this->getFilePath($key);
        $content = json_encode($data, JSON_THROW_ON_ERROR);

        // Atomic write (tmp dosyaya yaz, sonra rename)
        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(8));

        if (file_put_contents($tmpFile, $content, LOCK_EX) !== false) {
            rename($tmpFile, $file);
        } else {
            // Cleanup failed temp file
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * Cache dosya yolunu al
     */
    private function getFilePath(string $key): string
    {
        // Güvenli dosya adı oluştur
        $hash = hash('sha256', $key);
        return $this->cacheDir . '/' . $hash . '.rate';
    }

    /**
     * Redis anahtarını üret (namespace + hash).
     */
    private function redisKey(string $key): string
    {
        $prefix = $this->cache?->prefix ?? 'phpframe';
        return $prefix . ':rate:' . hash('sha256', $key);
    }

    /**
     * Lazy Redis bağlantısı. Yalnızca CACHE_DRIVER=redis ve phpredis varsa dener.
     * Erişilemezse bir kez işaretler ve null döner → dosya fallback (zarif degrade).
     */
    private function redis(): ?\Redis
    {
        if ($this->redisChecked) {
            return $this->redis;
        }
        $this->redisChecked = true;

        $driver = $this->cache?->driver ?? '';
        if ($driver !== 'redis' || !extension_loaded('redis')) {
            return $this->redis = null;
        }

        try {
            $redis = new \Redis();
            $host = $this->cache?->redis->host ?? '127.0.0.1';
            $port = $this->cache?->redis->port ?? 6379;
            $db   = $this->cache?->redis->database ?? 0;
            $persistent = $this->cache?->redis->persistent ?? false;

            $connected = $persistent
                ? $redis->pconnect($host, $port, 1.0, 'phpframe-rate:' . $db)
                : $redis->connect($host, $port, 1.0);

            if (!$connected) {
                throw new \RuntimeException('Redis connect başarısız');
            }
            $password = $this->cache?->redis->password ?? '';
            if ($password !== '') {
                $redis->auth($password);
            }
            if ($db !== 0) {
                $redis->select($db);
            }

            return $this->redis = $redis;
        } catch (\Throwable) {
            return $this->redis = null; // zarif degrade → dosya fallback
        }
    }

    /**
     * Veri expire olmuş mu?
     *
     * @param array{attempts: int, expires_at: int} $data
     */
    private function isExpired(array $data): bool
    {
        return !isset($data['expires_at']) || $data['expires_at'] <= time();
    }

    /**
     * Varsayılan limit değerlerini ayarla
     */
    public function setDefaults(int $maxAttempts, int $decaySeconds): self
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
        return $this;
    }

    /**
     * IP bazlı rate limiting için helper
     */
    public static function forIp(?string $ip = null, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $limiter = new self();

        return $limiter->attempt('ip:' . $ip, $maxAttempts, $decaySeconds);
    }

    /**
     * Route bazlı rate limiting için helper
     */
    public static function forRoute(string $route, ?string $ip = null, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $limiter = new self();

        return $limiter->attempt('route:' . $route . ':' . $ip, $maxAttempts, $decaySeconds);
    }
}
