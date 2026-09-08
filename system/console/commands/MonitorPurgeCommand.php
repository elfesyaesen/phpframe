<?php

declare(strict_types=1);

namespace System\Console\Commands;

use DateInterval;
use DateTimeImmutable;
use PDO;
use System\Config\AppConfig;
use System\Config\DatabaseConfig;
use System\Config\LogConfig;
use System\Config\MonitorConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;
use System\Database\Database;
use System\Monitor\NullRecorder;
use System\Logging\Handlers\FileHandler;
use System\Logging\LogLevel;
use System\Logging\Logger;
use System\Monitor\Storage\DatabaseStorage;
use Throwable;

/**
 * Monitor kayıtlarının saklama süresi (retention) uygulaması.
 *
 * Kaynak projenin en büyük operasyonel açığı buydu: istek log tablosunun hiç
 * temizlenmemesi. Tablo sınırsız büyüyor, dashboard yavaşlıyor ve istek/yanıt
 * gövdeleri (yani kişisel veri) süresiz saklanıyordu.
 *
 * Cron'a bağlanmalıdır:
 *   0 4 * * * cd /path/to/app && php frame monitor:purge
 *
 * `frame` CLI'da Container kurulmadığı ve `CommandLoader` komutları
 * `new $class()` ile oluşturduğu için bağımlılıklar burada elle kurulur —
 * `HealthCheckCommand` ile aynı kalıp.
 */
#[AsCommand(
    name: 'monitor:purge',
    description: 'MONITOR_RETENTION_DAYS gününden eski monitor kayıtlarını siler',
    usage: 'php frame monitor:purge [--days=7] [--chunk=5000] [--dry-run]'
)]
final class MonitorPurgeCommand extends Command
{
    private const TABLES = [
        DatabaseStorage::TABLE_REQUEST,
        DatabaseStorage::TABLE_QUERY,
        DatabaseStorage::TABLE_EXCEPTION,
    ];

    /**
     * Yapılandırma global sabitlerden değil enjekte edilen typed config'ten
     * okunur (DI-plan §25).
     */
    public function __construct(
        private readonly AppConfig $app,
        private readonly Database $database,
        private readonly MonitorConfig $monitor,
        private readonly DatabaseConfig $db,
        private readonly LogConfig $log,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            null,
            InputOption::VALUE_REQUIRED,
            'Kaç günden eski kayıtlar silinsin (varsayılan: MONITOR_RETENTION_DAYS)'
        );

        $this->addOption(
            'chunk',
            null,
            InputOption::VALUE_REQUIRED,
            'Tek DELETE turunda silinecek satır sayısı',
            5000
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Hiçbir şey silmez, yalnızca silinecek satır sayısını raporlar'
        );
    }

    protected function handle(): ExitCode
    {
        $days  = (int) $this->option('days', $this->monitor->retentionDays);
        $chunk = (int) $this->option('chunk', 5000);
        $dry   = (bool) $this->option('dry-run');

        if ($days < 0) {
            $this->error('--days negatif olamaz.');
            return ExitCode::FAILURE;
        }

        $this->title('Monitor Retention');

        try {
            $database = $this->database;
            $pdo      = $database->getConnection();
        } catch (Throwable $e) {
            $this->error('Veritabanına bağlanılamadı: ' . $e->getMessage());
            return ExitCode::FAILURE;
        }

        $missing = $this->missingTables($pdo, $database);
        if ($missing !== []) {
            $this->error('Monitor tabloları yok: ' . implode(', ', $missing));
            $this->note('Çalıştırın: php frame migrate');
            return ExitCode::FAILURE;
        }

        $cutoff = $this->cutoff($pdo, $days);
        $this->writeln('Kesim tarihi: ' . $cutoff->format('Y-m-d H:i:s') . " ({$days} gün)");
        $this->newLine();

        if ($dry) {
            return $this->reportDryRun($pdo, $database, $cutoff);
        }

        $storage = new DatabaseStorage(
            databaseProvider: static fn(): Database => $database,
            logger: $this->cliLogger(),
        );

        $deleted = $storage->purge($cutoff, $chunk);

        $this->success(number_format($deleted) . ' satır silindi.');

        return ExitCode::SUCCESS;
    }

    /**
     * Kesim tarihini VERİTABANININ saatinden hesaplar.
     *
     * `created_at` bir TIMESTAMP kolonudur: MySQL değeri UTC saklar ve oturum
     * saat dilimine göre çevirir. PHP tarafı ise APP_TIMEZONE'da çalışır. İki
     * saat dilimi farklıysa PHP'de üretilen bir kesim tarihi yanlış aralığı
     * siler — kaynak projede tam olarak bu uyumsuzluk vardı ve dashboard'da
     * saatler kaydırılarak telafi ediliyordu. Referans anı DB'den okuyarak
     * karşılaştırma tek ve doğru saat çerçevesinde yapılır.
     */
    private function cutoff(PDO $pdo, int $days): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        try {
            $value = $pdo->query('SELECT CURRENT_TIMESTAMP')?->fetchColumn();

            if (is_string($value) && $value !== '') {
                $now = new DateTimeImmutable($value);
            }
        } catch (Throwable) {
            // DB saati okunamadıysa PHP saatiyle devam et — purge yine çalışır,
            // yalnızca saat dilimi farkı kadar sapma olabilir.
            $this->warning('Veritabanı saati okunamadı; PHP saati kullanılıyor.');
        }

        return $now->sub(new DateInterval('P' . max(0, $days) . 'D'));
    }

    /**
     * @return array<int, string>
     */
    private function missingTables(PDO $pdo, Database $database): array
    {
        $missing = [];

        foreach (self::TABLES as $table) {
            $qualified = $database->getDriver()->quoteIdentifier($this->db->prefix . $table);

            try {
                $pdo->query('SELECT 1 FROM ' . $qualified . ' WHERE 1 = 0');
            } catch (Throwable) {
                $missing[] = $this->db->prefix . $table;
            }
        }

        return $missing;
    }

    private function reportDryRun(PDO $pdo, Database $database, DateTimeImmutable $cutoff): ExitCode
    {
        $rows  = [];
        $total = 0;

        foreach (self::TABLES as $table) {
            $qualified = $database->getDriver()->quoteIdentifier($this->db->prefix . $table);

            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM ' . $qualified . ' WHERE created_at < :cutoff'
            );
            $statement->execute(['cutoff' => $cutoff->format('Y-m-d H:i:s')]);

            $count  = (int) $statement->fetchColumn();
            $total += $count;

            $rows[] = [$this->db->prefix . $table, number_format($count)];
        }

        $this->table(['Tablo', 'Silinecek satır'], $rows);
        $this->newLine();
        $this->note('Deneme çalışması — hiçbir satır silinmedi. Toplam: ' . number_format($total));

        return ExitCode::SUCCESS;
    }

    /**
     * CLI için minimal logger.
     *
     * `DatabaseStorage` başarısızlıkları exception fırlatmak yerine loglar
     * (izleme isteği bozmaz sözleşmesi). Web'de bu logger container'dan gelir;
     * CLI'da container olmadığı için bootstrap'takiyle aynı hedefe yazan bir
     * örnek kurulur, böylece purge hataları da `logs/app.log`'ta görünür.
     */
    private function cliLogger(): Logger
    {
        $logger = new Logger();

        $logger->addHandler(
            new FileHandler(
                APP_ROOT . '/logs/app.log',
                LogLevel::WARNING,
                null,
                $this->log->maxFileSizeMb,
                $this->log->retention,
                $this->log->rotationPeriod
            )
        );

        return $logger;
    }
}
