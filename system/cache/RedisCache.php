<?php

declare(strict_types=1);

namespace System\Cache;

use System\Logging\Contracts\LoggerInterface;

final class RedisCache extends AbstractCache
{
    private const LOCK_TTL = 5;
    private const LOCK_WAIT_US = 50_000;

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

    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $miss = new \stdClass();

        $value = $this->get($key, $miss);
        if ($value !== $miss) {
            return $value;
        }

        $redis = $this->connection();
        if ($redis === null) {
            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        }

        $lockKey = $this->prefixed('__stampede_lock__:' . $key);

        try {
            $gotLock = (bool) $redis->set($lockKey, '1', ['nx', 'ex' => self::LOCK_TTL]);
        } catch (\Throwable) {
            $gotLock = true;
        }

        if ($gotLock) {
            try {
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
                }
            }
        }

        for ($i = 0; $i < self::LOCK_MAX_RETRIES; $i++) {
            usleep(self::LOCK_WAIT_US);
            $value = $this->get($key, $miss);
            if ($value !== $miss) {
                return $value;
            }
        }

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
            $this->unavailable = true;
            $this->logger?->warning('Redis kullanılamıyor; cache devre dışı, DB fallback aktif', [
                'host'  => $this->host,
                'port'  => $this->port,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
