<?php

declare(strict_types=1);

namespace System\Console\Commands;

use PDO;
use System\Config\AppConfig;
use System\Config\CacheConfig;
use System\Config\DatabaseConfig;
use System\Config\MediaConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;
use System\Database\Database;
use System\Database\MigrationRunner;
use System\Media\Image\ImageProcessor;

#[AsCommand(
    name: 'app:health',
    description: 'DB/Redis/migration/cache hazırlık kontrolü (deploy & probe için)',
    usage: 'php frame app:health [--json]',
    aliases: ['health']
)]
final class HealthCheckCommand extends Command
{

    private const STATUS_OK   = 'ok';
    private const STATUS_WARN = 'warn';
    private const STATUS_FAIL = 'fail';

    public function __construct(
        private readonly AppConfig $app,
        private readonly Database $database,
        private readonly MigrationRunner $runner,
        private readonly DatabaseConfig $db,
        private readonly CacheConfig $cacheConfig,
        private readonly MediaConfig $media,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Sonucu makine-okunur JSON olarak ver');
    }

    protected function handle(): ExitCode
    {
        /** @var array<int, array{name:string, status:string, detail:string}> $checks */
        $checks = [];

        $checks[] = [
            'name'   => 'PHP sürümü',
            'status' => PHP_VERSION_ID >= 80500 ? self::STATUS_OK : self::STATUS_FAIL,
            'detail' => PHP_VERSION,
        ];

        $pdoOk = in_array($this->db->driver->value, PDO::getAvailableDrivers(), true);
        $checks[] = [
            'name'   => 'PDO sürücüsü',
            'status' => $pdoOk ? self::STATUS_OK : self::STATUS_FAIL,
            'detail' => $pdoOk ? $this->db->driver->value : "'" . $this->db->driver->value . "' yüklü değil (pdo_" . $this->db->driver->value . ')',
        ];

        $db = null;
        if ($pdoOk) {
            try {
                $db = $this->database;
                $db->getConnection()->query('SELECT 1');
                $checks[] = [
                    'name'   => 'Veritabanı bağlantısı',
                    'status' => self::STATUS_OK,
                    'detail' => sprintf('%s@%s:%s/%s', $this->db->user, $this->db->host, $this->db->port, $this->db->name),
                ];
            } catch (\Throwable $e) {
                $db = null;
                $checks[] = [
                    'name'   => 'Veritabanı bağlantısı',
                    'status' => self::STATUS_FAIL,
                    'detail' => $this->trimError($e->getMessage()),
                ];
            }
        }

        if ($db !== null) {
            try {
                $runner  = $this->runner;
                $pending = $runner->getPendingMigrations();
                $count   = count($pending);
                $checks[] = [
                    'name'   => 'Migration durumu',
                    'status' => $count === 0 ? self::STATUS_OK : self::STATUS_WARN,
                    'detail' => $count === 0 ? 'güncel' : "{$count} bekleyen migration (php frame migrate)",
                ];
            } catch (\Throwable $e) {
                $checks[] = [
                    'name'   => 'Migration durumu',
                    'status' => self::STATUS_WARN,
                    'detail' => $this->trimError($e->getMessage()),
                ];
            }
        }

        $checks[] = $this->checkRedis();

        $opcacheLoaded = extension_loaded('Zend OPcache');
        $status        = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $opcacheActive = is_array($status) && !empty($status['opcache_enabled']);
        $checks[] = [
            'name'   => 'OPcache',
            'status' => $opcacheLoaded ? self::STATUS_OK : self::STATUS_WARN,
            'detail' => $opcacheActive
                ? 'aktif'
                : ($opcacheLoaded ? 'CLI\'da kapalı (FPM\'de ayrı; opcache.enable_cli)' : 'eklenti yüklü değil'),
        ];

        $checks[] = $this->checkCompiledCache(
            'Route cache',
            $this->app->cachePath('router', 'routes.cache.php'),
            'php frame route:cache'
        );

        $checks[] = $this->checkImageDriver();

        foreach ($this->configWarnings() as $index => $warning) {
            $checks[] = [
                'name'   => 'Config uyarısı ' . ($index + 1),
                'status' => self::STATUS_WARN,
                'detail' => $warning,
            ];
        }

        $exit = $this->worstExit($checks);

        if ($this->option('json')) {
            $this->renderJson($checks, $exit);
            return $exit;
        }

        $this->render($checks, $exit);
        return $exit;
    }

    /**
     * @return array{name:string, status:string, detail:string}
     */
    private function checkRedis(): array
    {
        if ($this->cacheConfig->driver !== 'redis') {
            return ['name' => 'Redis cache', 'status' => self::STATUS_OK, 'detail' => 'devre dışı (CACHE_DRIVER=' . $this->cacheConfig->driver . ')'];
        }

        if (!extension_loaded('redis')) {
            return ['name' => 'Redis cache', 'status' => self::STATUS_WARN, 'detail' => 'phpredis eklentisi yok — NullCache fallback (DB yükü artar)'];
        }

        try {
            $redis = new \Redis();
            if (!@$redis->connect($this->cacheConfig->redis->host, $this->cacheConfig->redis->port, 1.0)) {
                throw new \RuntimeException('connect başarısız');
            }
            if ($this->cacheConfig->redis->password !== '') {
                $redis->auth($this->cacheConfig->redis->password);
            }
            $pong = $redis->ping();
            $redis->close();

            return ['name' => 'Redis cache', 'status' => self::STATUS_OK, 'detail' => sprintf('%s:%s db%s', $this->cacheConfig->redis->host, $this->cacheConfig->redis->port, $this->cacheConfig->redis->database)];
        } catch (\Throwable $e) {
            return ['name' => 'Redis cache', 'status' => self::STATUS_WARN, 'detail' => 'erişilemiyor → degrade aktif (' . $this->trimError($e->getMessage()) . ')'];
        }
    }

    /**
     * @return list<string>
     */
    /**
     * @return array{name:string, status:string, detail:string}
     */
    private function checkImageDriver(): array
    {
        $driver = (new ImageProcessor($this->media))->driver();

        if ($driver === null) {
            return [
                'name'   => 'Resim sürücüsü',
                'status' => self::STATUS_WARN,
                'detail' => 'yok — resim yüklemeleri reddedilir (php.ini: extension=gd, extension=exif)',
            ];
        }

        $formats = ['webp'];
        if ($driver->supports('avif')) {
            $formats[] = 'avif';
        }

        $orientation = $driver->name() === 'gd' && !function_exists('exif_read_data')
            ? ', exif yok: fotoğraf yönü düzeltilmez'
            : '';

        return [
            'name'   => 'Resim sürücüsü',
            'status' => $orientation === '' ? self::STATUS_OK : self::STATUS_WARN,
            'detail' => $driver->name() . ' (' . implode(', ', $formats) . ')' . $orientation,
        ];
    }

    private function configWarnings(): array
    {
        try {
            return \System\Config\ConfigFactory::forRoot(APP_ROOT)->warnings();
        } catch (\Throwable) {
            return [];
        }
    }

    private function checkCompiledCache(string $name, string $file, string $buildCmd): array
    {
        if (is_file($file)) {
            return ['name' => $name, 'status' => self::STATUS_OK, 'detail' => 'derlenmiş (' . $this->formatBytes((int) filesize($file)) . ')'];
        }

        return [
            'name'   => $name,
            'status' => !$this->app->production ? self::STATUS_OK : self::STATUS_WARN,
            'detail' => !$this->app->production ? 'debug modda devre dışı (normal)' : "yok — çalıştır: {$buildCmd}",
        ];
    }

    /**
     * @param array<int, array{name:string, status:string, detail:string}> $checks
     */
    private function worstExit(array $checks): ExitCode
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::STATUS_FAIL) {
                return ExitCode::FAILURE;
            }
        }
        return ExitCode::SUCCESS;
    }

    /**
     * @param array<int, array{name:string, status:string, detail:string}> $checks
     */
    private function render(array $checks, ExitCode $exit): void
    {
        $this->title('Sağlık Kontrolü');

        $rows = [];
        foreach ($checks as $check) {
            $rows[] = [$this->badge($check['status']), $check['name'], $check['detail']];
        }
        $this->table(['Durum', 'Kontrol', 'Detay'], $rows);
        $this->newLine();

        if ($exit === ExitCode::SUCCESS) {
            if ($this->hasWarning($checks)) {
                $this->warning('Hazır — ancak uyarılar var (yukarı bakın).');
            } else {
                $this->success('Tüm kontroller sağlıklı.');
            }
        } else {
            $this->error('Kritik bağımlılık kullanılamıyor — uygulama trafik alamaz.');
        }
    }

    /**
     * @param array<int, array{name:string, status:string, detail:string}> $checks
     */
    private function hasWarning(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === self::STATUS_WARN) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array{name:string, status:string, detail:string}> $checks
     */
    private function renderJson(array $checks, ExitCode $exit): void
    {
        $payload = [
            'status' => $exit === ExitCode::SUCCESS ? 'pass' : 'fail',
            'checks' => $checks,
        ];
        $this->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function badge(string $status): string
    {
        return match ($status) {
            self::STATUS_OK   => $this->output->green('✓ OK'),
            self::STATUS_WARN => $this->output->yellow('‼ WARN'),
            default           => $this->output->red('✗ FAIL'),
        };
    }

    private function trimError(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;
        return mb_strlen($message) > 80 ? mb_substr($message, 0, 77) . '...' : $message;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
