<?php

declare(strict_types=1);

namespace System\Console\Commands;

use PDO;
use System\Config\AppConfig;
use System\Config\AuthConfig;
use System\Config\CacheConfig;
use System\Config\DatabaseConfig;
use System\Config\HttpConfig;
use System\Config\LogConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;

/**
 * Çalışan ortamın teşhis özeti (runtime + config + extensions).
 *
 * Amaç dev/prod PARİTESİ: aynı komut her iki ortamda çalışır ve farkı
 * (derlenmiş container, OPcache, preload, debug bayrakları) görünür kılar.
 * Hassas değerler (parolalar, secret) maskeli gösterilir.
 */
#[AsCommand(
    name: 'app:about',
    description: 'Ortam, runtime, config ve eklenti özetini gösterir',
    usage: 'php frame app:about',
    aliases: ['about']
)]
final class AboutCommand extends Command
{
    /**
     * Yapılandırma global sabitlerden değil enjekte edilen typed config'ten
     * okunur (DI-plan §25).
     */
    public function __construct(
        private readonly AppConfig $app,
        private readonly DatabaseConfig $db,
        private readonly CacheConfig $cacheConfig,
        private readonly AuthConfig $auth,
        private readonly HttpConfig $http,
        private readonly LogConfig $log,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function handle(): ExitCode
    {
        $this->title('PHPFrame — Ortam Özeti');

        $this->section('Uygulama');
        $this->kv([
            'Ortam'      => $this->app->production ? $this->output->green('production') : $this->output->yellow('development (APP_PRODUCTION=false)'),
            'URL'        => $this->app->url,
            'Timezone'   => $this->app->timezone,
        ]);

        $this->section('PHP Runtime');
        $this->kv([
            'Sürüm'      => $this->boolColor(PHP_VERSION_ID >= 80500, PHP_VERSION),
            'SAPI'       => PHP_SAPI,
            'OPcache'    => $this->opcacheSummary(),
            'JIT'        => $this->jitSummary(),
            'Preload'    => $this->yesNo((string) ini_get('opcache.preload') !== ''),
            'Bellek'     => ini_get('memory_limit') ?: 'n/a',
        ]);

        $this->section('Eklentiler');
        $this->kv([
            'pdo_' . $this->db->driver->value => $this->yesNo(in_array($this->db->driver->value, PDO::getAvailableDrivers(), true)),
            'redis (phpredis)' => $this->yesNo(extension_loaded('redis')),
            'opcache'          => $this->yesNo(extension_loaded('Zend OPcache')),
            'apcu'             => $this->yesNo(extension_loaded('apcu')),
        ]);

        $this->section('Veritabanı');
        $this->kv([
            'Sürücü'   => $this->db->driver->value,
            'Host'     => $this->db->host . ':' . $this->db->port,
            'Veritabanı' => $this->db->name,
            'Prefix'   => $this->db->prefix !== '' ? $this->db->prefix : '(yok)',
            'Parola'   => $this->mask((string) $this->db->password),
        ]);

        $this->section('Cache');
        $this->kv([
            'Sürücü'      => $this->cacheConfig->driver,
            'Prefix'      => $this->cacheConfig->prefix,
            'Varsayılan TTL' => $this->cacheConfig->defaultTtl . ' sn',
            'Redis'       => $this->cacheConfig->driver === 'redis' ? sprintf('%s:%s db%s', $this->cacheConfig->redis->host, $this->cacheConfig->redis->port, $this->cacheConfig->redis->database) : '(kullanılmıyor)',
            'Redis parolası' => $this->cacheConfig->driver === 'redis' ? $this->mask((string) $this->cacheConfig->redis->password) : '-',
        ]);

        $this->section('Güvenlik');
        $this->kv([
            'SECRET_KEY'       => $this->auth->secretKey !== '' ? $this->output->green('ayarlı') : $this->output->red('AYARLI DEĞİL'),
            'Access token TTL' => $this->auth->accessTokenExpire . ' sn',
            'Refresh token TTL' => $this->auth->refreshTokenExpire . ' sn',
            'CORS origin'      => $this->http->corsOrigins === [] ? '(tanımsız)' : implode(', ', $this->http->corsOrigins),
            'Trusted proxy'    => implode(', ', $this->http->trustedProxies),
        ]);

        $this->section('Loglama');
        $this->kv([
            'Rotation'  => $this->log->rotationPeriod,
            'Retention' => $this->log->retention . ' gün',
            'Maks. dosya' => $this->log->maxFileSizeMb . ' MB',
        ]);

        $this->newLine();

        if (!$this->app->production) {
            $this->note('Development modu — derlenmiş cache\'ler (container graph, route cache) devre dışıdır. Production hazırlığı için: php frame optimize');
        }

        return ExitCode::SUCCESS;
    }

    /**
     * @param array<string, string> $pairs
     */
    private function kv(array $pairs): void
    {
        $width = 0;
        foreach (array_keys($pairs) as $key) {
            $width = max($width, mb_strlen($key));
        }
        foreach ($pairs as $key => $value) {
            // mb-aware padding: str_pad bayt sayar, Türkçe karakterlerde hizayı bozar.
            $pad = str_repeat(' ', max(0, $width - mb_strlen($key)));
            $this->writeln(sprintf('  %s%s  %s', $this->output->gray($key), $pad, $value));
        }
    }

    private function opcacheSummary(): string
    {
        if (!function_exists('opcache_get_status')) {
            return $this->output->yellow('CLI\'da kapalı (FPM ayrı)');
        }
        $status = @opcache_get_status(false);
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return $this->output->yellow('devre dışı');
        }
        $validate = ini_get('opcache.validate_timestamps');
        $mode = ($validate === '0' || $validate === '' || $validate === false)
            ? 'validate_timestamps=0 (prod)'
            : 'validate_timestamps=1 (dev)';
        return $this->output->green('aktif') . $this->output->gray(' — ' . $mode);
    }

    private function jitSummary(): string
    {
        if (!function_exists('opcache_get_status')) {
            return $this->output->gray('n/a (CLI)');
        }
        $status = @opcache_get_status(false);
        $enabled = is_array($status) && !empty($status['jit']['enabled']);
        return $enabled ? $this->output->green('aktif') : $this->output->gray('kapalı');
    }

    private function yesNo(bool $value): string
    {
        return $value ? $this->output->green('✓ yüklü') : $this->output->red('✗ yok');
    }

    private function boolColor(bool $ok, string $text): string
    {
        return $ok ? $this->output->green($text) : $this->output->red($text);
    }

    private function mask(string $secret): string
    {
        if ($secret === '') {
            return $this->output->gray('(boş)');
        }
        $len = mb_strlen($secret);
        return $this->output->gray(str_repeat('•', min($len, 8)) . " ({$len} karakter)");
    }
}
