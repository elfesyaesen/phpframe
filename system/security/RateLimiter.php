<?php

declare(strict_types=1);

namespace System\Security;

use System\Config\CacheConfig;

final class RateLimiter
{
    private string $cacheDir;

    private int $maxAttempts = 60;
    private int $decaySeconds = 60;

    private ?\Redis $redis = null;
    private bool $redisChecked = false;

    /**
     * @param CacheConfig|null $cache
     */
    public function __construct(?string $cacheDir = null, private readonly ?CacheConfig $cache = null)
    {
        $this->cacheDir = $cacheDir ?? (defined('APP_ROOT') ? APP_ROOT . '/storage/cache/rate' : sys_get_temp_dir() . '/rate');

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @param string $key
     * @param int $maxAttempts
     * @param int $decaySeconds
     * @return bool
     */
    public function attempt(string $key, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        $redis = $this->redis();
        if ($redis !== null) {
            $result = $this->attemptRedis($redis, $key, $maxAttempts, $decaySeconds);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->attemptFile($key, $maxAttempts, $decaySeconds);
    }

    /**
     * @return bool|null
     */
    private function attemptRedis(\Redis $redis, string $key, int $maxAttempts, int $decaySeconds): ?bool
    {
        try {
            $k = $this->redisKey($key);
            $redis->set($k, 0, ['nx', 'ex' => $decaySeconds]);
            $count = (int) $redis->incr($k);
            return $count <= $maxAttempts;
        } catch (\Throwable) {
            $this->redis = null;
            return null;
        }
    }

    private function attemptFile(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $file = $this->getFilePath($key);

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
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
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function tooManyAttempts(string $key, int $maxAttempts = 60): bool
    {
        return $this->attempts($key) >= $maxAttempts;
    }

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

    public function remaining(string $key, int $maxAttempts = 60): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

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

    public function retryAfter(string $key): int
    {
        return $this->availableIn($key);
    }

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

    public function clear(string $key): void
    {
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                $redis->del($this->redisKey($key));
            } catch (\Throwable) {
            }
            return;
        }

        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            @unlink($file);
        }
    }

    public function clearAll(): void
    {
        $files = glob($this->cacheDir . '/*.rate');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

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
     * @param array{attempts: int, expires_at: int} $data
     */
    private function setData(string $key, array $data): void
    {
        $file = $this->getFilePath($key);
        $content = json_encode($data, JSON_THROW_ON_ERROR);

        $tmpFile = $file . '.tmp.' . bin2hex(random_bytes(8));

        if (file_put_contents($tmpFile, $content, LOCK_EX) !== false) {
            rename($tmpFile, $file);
        } else {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    private function getFilePath(string $key): string
    {
        $hash = hash('sha256', $key);
        return $this->cacheDir . '/' . $hash . '.rate';
    }

    private function redisKey(string $key): string
    {
        $prefix = $this->cache?->prefix ?? 'phpframe';
        return $prefix . ':rate:' . hash('sha256', $key);
    }

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
            return $this->redis = null;
        }
    }

    /**
     * @param array{attempts: int, expires_at: int} $data
     */
    private function isExpired(array $data): bool
    {
        return !isset($data['expires_at']) || $data['expires_at'] <= time();
    }

    public function setDefaults(int $maxAttempts, int $decaySeconds): self
    {
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
        return $this;
    }

    public static function forIp(?string $ip = null, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $limiter = new self();

        return $limiter->attempt('ip:' . $ip, $maxAttempts, $decaySeconds);
    }

    public static function forRoute(string $route, ?string $ip = null, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        $ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $limiter = new self();

        return $limiter->attempt('route:' . $route . ':' . $ip, $maxAttempts, $decaySeconds);
    }
}
