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
        $days  = $this->positiveInt('days', $this->option('days', $this->monitor->retentionDays));
        $chunk = $this->positiveInt('chunk', $this->option('chunk', 5000));
        $dry   = (bool) $this->option('dry-run');

        if ($days === null || $chunk === null) {
            return ExitCode::INVALID;
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

        try {
            $deleted = $storage->purgeOrFail($cutoff, $chunk);
        } catch (Throwable $e) {
            $this->error('Silme başarısız: ' . $e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->success(number_format($deleted) . ' satır silindi.');

        return ExitCode::SUCCESS;
    }

    private function positiveInt(string $option, mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        $this->error("--{$option} pozitif bir tam sayı olmalı, verilen: '" . (is_scalar($value) ? $value : get_debug_type($value)) . "'");

        return null;
    }

    private function cutoff(PDO $pdo, int $days): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        try {
            $value = $pdo->query('SELECT CURRENT_TIMESTAMP')?->fetchColumn();

            if (is_string($value) && $value !== '') {
                $now = new DateTimeImmutable($value);
            }
        } catch (Throwable) {
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

    private function cliLogger(): Logger
    {
        $logger = new Logger();

        $logger->addHandler(
            new FileHandler(
                $this->log->applicationLog(),
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
