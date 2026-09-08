<?php

declare(strict_types=1);

namespace System\Cache;

use System\Config\CacheConfig;
use System\Logging\Contracts\LoggerInterface;

/**
 * Yapılandırmaya göre cache sürücüsü kurar.
 *
 * Artık STATIC DEĞİL ve global sabit OKUMUYOR: `CacheConfig` enjekte alır.
 * Eskiden `defined('CACHE_DRIVER') ? CACHE_DRIVER : 'redis'` biçiminde 8 ayrı
 * sabit okuyordu; her biri "sabit tanımlı mı" kontrolü gerektiriyordu çünkü
 * sınıf, config'in yüklenip yüklenmediğini bilemiyordu. Typed config bu
 * belirsizliği ortadan kaldırır — obje varsa değerler vardır.
 */
final class CacheFactory
{
    public function __construct(
        private readonly CacheConfig $config,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function create(): CacheInterface
    {
        return match ($this->config->driver) {
            'redis' => $this->makeRedis(),
            'array' => new ArrayCache($this->config->prefix, $this->config->defaultTtl),
            default => new NullCache($this->config->prefix, $this->config->defaultTtl),
        };
    }

    /**
     * Redis sürücüsü; eklenti yoksa zarif degrade (NullCache).
     *
     * Degrade sessiz DEĞİL: NullCache her okumada miss döndürür, yani
     * uygulama çalışır ama cache faydası olmaz. Eklentinin varlığı
     * `app:health` çıktısında görünür.
     */
    private function makeRedis(): CacheInterface
    {
        if (!extension_loaded('redis')) {
            $this->logger?->warning(
                'redis eklentisi yüklü değil — cache devre dışı (NullCache). '
                . 'CACHE_DRIVER=redis ayarlanmış ancak sürücü kullanılamıyor.',
                ['driver' => 'redis']
            );

            return new NullCache($this->config->prefix, $this->config->defaultTtl);
        }

        $redis = $this->config->redis;

        return new RedisCache(
            host: $redis->host,
            port: $redis->port,
            password: $redis->password,
            database: $redis->database,
            prefix: $this->config->prefix,
            defaultTtl: $this->config->defaultTtl,
            logger: $this->logger,
            persistent: $redis->persistent,
        );
    }
}
