<?php

declare(strict_types=1);

namespace System\Console\Commands;

use PDO;
use System\Config\AppConfig;
use System\Config\CacheConfig;
use System\Config\DatabaseConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;
use System\Container\Compilation\AtomicWriter;
use System\Database\Database;
use System\Database\MigrationRunner;

/**
 * Üretim sağlık/hazırlık (readiness) kontrolü.
 *
 * Deploy smoke-test'i, orchestrator/LB probe'u veya operatör teşhisi olarak
 * kullanılır. Kontroller BAĞIMSIZDIR: biri düşerse diğerleri koşmaya devam
 * eder, böylece tek bir çıktıda tüm arıza tablosu görülür.
 *
 * Çıkış kodu sözleşmesi:
 *  - 0 (SUCCESS): uygulama trafik alabilir (kritik bağımlılıklar sağlıklı).
 *  - 1 (FAILURE): kritik bir bağımlılık (DB / PDO sürücüsü) kullanılamıyor.
 * Redis kesintisi, bekleyen migration veya soğuk cache "warning"dir; cache-first
 * tasarım gereği uygulamayı düşürmez, bu yüzden çıkış kodunu FAILURE yapmaz.
 */
#[AsCommand(
    name: 'app:health',
    description: 'DB/Redis/migration/cache hazırlık kontrolü (deploy & probe için)',
    usage: 'php frame app:health [--json]',
    aliases: ['health']
)]
final class HealthCheckCommand extends Command
{
    use \System\Console\Commands\Concerns\BuildsContainer;

    private const STATUS_OK   = 'ok';
    private const STATUS_WARN = 'warn';
    private const STATUS_FAIL = 'fail';

    /**
     * Yapılandırma global sabitlerden değil enjekte edilen typed config'ten
     * okunur (DI-plan §25).
     */
    public function __construct(
        private readonly AppConfig $app,
        private readonly Database $database,
        private readonly MigrationRunner $runner,
        private readonly DatabaseConfig $db,
        private readonly CacheConfig $cacheConfig,
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

        // 1) PHP sürümü. Asgari sürüm bariyeri config/config.php içindedir.
        $checks[] = [
            'name'   => 'PHP sürümü',
            'status' => PHP_VERSION_ID >= 80500 ? self::STATUS_OK : self::STATUS_FAIL,
            'detail' => PHP_VERSION,
        ];

        // 2) PDO sürücüsü (kritik)
        $pdoOk = in_array($this->db->driver->value, PDO::getAvailableDrivers(), true);
        $checks[] = [
            'name'   => 'PDO sürücüsü',
            'status' => $pdoOk ? self::STATUS_OK : self::STATUS_FAIL,
            'detail' => $pdoOk ? $this->db->driver->value : "'" . $this->db->driver->value . "' yüklü değil (pdo_" . $this->db->driver->value . ')',
        ];

        // 3) Veritabanı bağlantısı (kritik) + 4) migration durumu
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

        // 5) Redis (cache-first) — yalnızca $this->cacheConfig->driver=redis ise anlamlı; kesinti = warning
        $checks[] = $this->checkRedis();

        // 6) OPcache (üretimde performans için kritik, dev'de opsiyonel)
        // Eklenti yüklüyse OK; CLI'da enable_cli=0 ile kapalı olması normaldir,
        // FPM'de ayrı çalışır — bu yüzden "kapalı" durumu uyarı değildir.
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

        // 7) Derlenmiş çekirdek cache'leri (yalnızca production'da beklenir)
        // Derlenmiş container tek dosya değil (sınıf + metadata + pointer);
        // varlık kontrolü pointer üzerinden yapılır ve BAYATLIK da denetlenir.
        // Bayat bir derleme "var" görünür ama son deploy'un tanımlarını koşar.
        $checks[] = $this->checkCompiledContainer();
        $checks[] = $this->checkCompiledCache(
            'Route cache',
            APP_ROOT . '/system/cache/router/routes.cache.php',
            'php frame cache:warm'
        );

        // 8) Yapılandırma uyarıları — "şu an zararsız ama bir ayar değişirse
        // tehlikeli" sınıfı. Boot'u durdurmaz ama sessiz de kalmaz
        // (bkz. ConfigValidator::warnings).
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
            // Graceful degrade: kesinti FAILURE değil, WARNING.
            return ['name' => 'Redis cache', 'status' => self::STATUS_WARN, 'detail' => 'erişilemiyor → degrade aktif (' . $this->trimError($e->getMessage()) . ')'];
        }
    }

    /**
     * @return array{name:string, status:string, detail:string}
     */
    /**
     * Derlenmiş DI container'ı: var mı ve GÜNCEL mi?
     *
     * Bayatlık kontrolü kritik: bayat bir derleme "var" görünür ama son
     * deploy'un tanımlarını koşar — yeni servisler sessizce kaybolur.
     * Production'da bu bir FAIL'dir, uyarı değil: uygulama yanlış tanım
     * kümesiyle çalışıyor demektir.
     *
     * Hash hesabı için tanımlar yeniden kurulur; bu yalnızca CLI'da yapılır,
     * çalışan bir istek ASLA hash hesaplamaz (DI-plan §31).
     *
     * @return array{name:string, status:string, detail:string}
     */
    /**
     * Yapılandırma uyarıları.
     *
     * `ConfigFactory` yeniden kurulur (container'daki config objeleri zaten
     * doğrulanmış hâlde geldiği için uyarı listesini taşımıyorlar). CLI'da
     * bu maliyet önemsiz; çalışan bir istek ASLA yapılandırmayı yeniden
     * denetlemez.
     *
     * @return list<string>
     */
    private function configWarnings(): array
    {
        try {
            return \System\Config\ConfigFactory::forRoot(APP_ROOT)->warnings();
        } catch (\Throwable) {
            return [];
        }
    }

    private function checkCompiledContainer(): array
    {
        $name = 'Derlenmiş container';
        $loader = $this->loader();
        $liveHash = $loader->liveHash();

        if ($liveHash === null) {
            // Debug modda derlenmiş container kasıtlı yoktur.
            return [
                'name'   => $name,
                'status' => $this->app->production ? self::STATUS_FAIL : self::STATUS_OK,
                'detail' => $this->app->production
                    ? 'YOK — production boot edemez; çalıştır: php frame container:compile'
                    : 'debug modda devre dışı (normal)',
            ];
        }

        // Karşılaştırma, canlı derlemeyi ÜRETEN modla yapılmalı. Burada mod
        // sabit `true` (content) yazılıydı; oysa `container:compile` dev
        // modunda (mtime+size) yazıyor. İki mod aynı kaynak ağacı için farklı
        // hash ürettiği için kontrol taze bir derlemeyi HER ZAMAN bayat
        // sanıyordu — production'da FAIL, yani doğru bir deploy'u kıran
        // sahte alarm (`bin/build.sh` son adımı bu komut).
        //
        // Mod bilinmiyorsa (bu alan eklenmeden önce yazılmış pointer) iki mod
        // da denenir: biri eşleşiyorsa derleme tazedir.
        $mode = $loader->liveHashMode();

        $modes = match ($mode) {
            AtomicWriter::HASH_MODE_CONTENT => [true],
            AtomicWriter::HASH_MODE_MTIME => [false],
            default => [false, true],
        };

        try {
            $currentHash = null;

            foreach ($modes as $useContentHash) {
                $candidate = $this->compiler(useContentHash: $useContentHash)->currentHash();

                // İlk hesap raporlanacak olan; eşleşen varsa o kazanır.
                $currentHash ??= $candidate;

                if ($candidate === $liveHash) {
                    $currentHash = $candidate;
                    break;
                }
            }
        } catch (\Throwable $e) {
            return [
                'name'   => $name,
                'status' => self::STATUS_WARN,
                'detail' => 'hash doğrulanamadı: ' . strtok($e->getMessage(), "\n"),
            ];
        }

        if ($liveHash !== $currentHash) {
            return [
                'name'   => $name,
                'status' => $this->app->production ? self::STATUS_FAIL : self::STATUS_WARN,
                'detail' => 'BAYAT (' . substr($liveHash, 0, 10) . '… ≠ '
                    . substr($currentHash, 0, 10) . '…) — çalıştır: php frame container:compile',
            ];
        }

        return [
            'name'   => $name,
            'status' => self::STATUS_OK,
            'detail' => 'güncel (' . substr($liveHash, 0, 12) . '…)',
        ];
    }

    private function checkCompiledCache(string $name, string $file, string $buildCmd): array
    {
        if (is_file($file)) {
            return ['name' => $name, 'status' => self::STATUS_OK, 'detail' => 'derlenmiş (' . $this->formatBytes((int) filesize($file)) . ')'];
        }

        // Debug modda bu cache'ler kasıtlı yoktur → bilgi/warn değil OK kabul edilir.
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
