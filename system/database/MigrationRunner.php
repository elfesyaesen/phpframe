<?php

declare(strict_types=1);

namespace System\Database;

use PDO;
use System\Config\AppConfig;
use System\Config\DatabaseConfig;
use RuntimeException;

/**
 * Migration işlemlerini yöneten sınıf.
 */
class MigrationRunner
{
    private readonly Schema $schema;
    private readonly DatabaseDriver $driver;
    private readonly string $prefix;
    private string $migrationPath;
    private string $migrationTable;

    /**
     * Bağlantı LAZY alınır (`pdo()`), constructor'da AÇILMAZ — DI-plan §14.
     * Sürücü, tablo öneki ve migration dizini config'ten gelir; hiçbiri
     * veritabanı bağlantısı gerektirmez.
     */
    public function __construct(
        private readonly Database $database,
        Schema $schema,
        DatabaseConfig $config,
        AppConfig $app,
    ) {
        $this->schema = $schema;
        $this->driver = $config->driver;
        $this->prefix = $config->prefix;
        $this->migrationPath = $app->path('migration');
        $this->migrationTable = 'migrations';
    }

    private function pdo(): PDO
    {
        return $this->database->getConnection();
    }

    /**
     * Identifier'ı (tablo/kolon adı) aktif sürücüye göre tırnaklar.
     * MySQL → `backtick`, SQL Server → [bracket], diğerleri (PG/SQLite) → "ANSI".
     */
    private function q(string $identifier): string
    {
        return match ($this->driver) {
            DatabaseDriver::MYSQL     => "`{$identifier}`",
            DatabaseDriver::SQLSERVER => "[{$identifier}]",
            default                   => "\"{$identifier}\"",
        };
    }

    /**
     * Migration tablosunun tırnaklanmış tam adı (tablo öneki dahil).
     */
    private function migrationTableId(): string
    {
        return $this->q($this->prefix . $this->migrationTable);
    }

    /**
     * Migration path'ini değiştirir.
     */
    public function setMigrationPath(string $path): void
    {
        $this->migrationPath = $path;
    }

    /**
     * Migration tablosunun varlığını kontrol eder ve yoksa oluşturur.
     */
    public function ensureMigrationTableExists(): void
    {
        if (!$this->schema->hasTable($this->migrationTable)) {
            $this->createMigrationTable();
        }
    }

    /**
     * Migration tablosunu oluşturur.
     */
    private function createMigrationTable(): void
    {
        // ensureMigrationTableExists() zaten hasTable() ile koruduğu için "IF NOT
        // EXISTS" gerekmez (SQL Server onu desteklemez; böylece 4 sürücü de uyumlu).
        $table = $this->migrationTableId();

        // Auto-increment PK ifadesi sürücüye göre farklıdır.
        $autoPk = match ($this->driver) {
            DatabaseDriver::MYSQL      => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            DatabaseDriver::SQLITE     => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            DatabaseDriver::SQLSERVER  => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
            DatabaseDriver::POSTGRESQL => 'BIGSERIAL PRIMARY KEY',
        };

        // executed_at varsayılanı: SQL Server'da TIMESTAMP bir rowversion tipidir,
        // datetime için DATETIME2 kullanılır; diğerlerinde TIMESTAMP geçerlidir.
        $tsType = $this->driver === DatabaseDriver::SQLSERVER ? 'DATETIME2' : 'TIMESTAMP';

        $sql = sprintf(
            'CREATE TABLE %s (
                %s %s,
                %s VARCHAR(255) NOT NULL,
                %s INTEGER NOT NULL,
                %s %s NOT NULL DEFAULT CURRENT_TIMESTAMP
            )',
            $table,
            $this->q('id'), $autoPk,
            $this->q('migration'),
            $this->q('batch'),
            $this->q('executed_at'), $tsType
        );

        $this->pdo()->exec($sql);
    }

    /**
     * Bekleyen migration'ları çalıştırır.
     *
     * @return array<string> Çalıştırılan migration'lar
     */
    public function migrate(bool $step = false): array
    {
        $this->ensureMigrationTableExists();

        $pendingMigrations = $this->getPendingMigrations();

        if (empty($pendingMigrations)) {
            return [];
        }

        $batch = $this->getNextBatchNumber();
        $ran = [];

        foreach ($pendingMigrations as $migration) {
            // Şema değişikliği ile migration kaydını birlikte sar. UYARI: yalnızca
            // PostgreSQL/SQLite transactional DDL destekler; MySQL'de CREATE/ALTER/DROP
            // implicit commit tetikler, rollBack şemayı geri ALMAZ. MySQL'de güvenlik
            // için migration'lar idempotent yazılmalı (IF NOT EXISTS / hasColumn kontrolü).
            $this->transactional(function () use ($migration, $batch): void {
                $this->runMigration($migration, 'up');
                $this->recordMigration($migration, $batch);
            });
            $ran[] = $migration;

            if ($step) {
                break;
            }
        }

        return $ran;
    }

    /**
     * Son batch'i geri alır.
     *
     * @return array<string> Geri alınan migration'lar
     */
    public function rollback(int $steps = 1): array
    {
        $this->ensureMigrationTableExists();

        $migrations = $this->getMigrationsToRollback($steps);

        if (empty($migrations)) {
            return [];
        }

        $rolledBack = [];

        foreach ($migrations as $migration) {
            $this->transactional(function () use ($migration): void {
                $this->runMigration($migration, 'down');
                $this->removeMigration($migration);
            });
            $rolledBack[] = $migration;
        }

        return $rolledBack;
    }

    /**
     * Tüm migration'ları geri alır.
     *
     * @return array<string> Geri alınan migration'lar
     */
    public function reset(): array
    {
        $this->ensureMigrationTableExists();

        $migrations = $this->getRanMigrations();

        if (empty($migrations)) {
            return [];
        }

        // Ters sırada çalıştır
        $migrations = array_reverse($migrations);
        $rolledBack = [];

        foreach ($migrations as $migration) {
            $this->transactional(function () use ($migration): void {
                $this->runMigration($migration, 'down');
                $this->removeMigration($migration);
            });
            $rolledBack[] = $migration;
        }

        return $rolledBack;
    }

    /**
     * Tüm migration'ları geri alır ve tekrar çalıştırır.
     *
     * @return array<string, array<string>> ['reset' => [...], 'migrate' => [...]]
     */
    public function refresh(): array
    {
        $reset = $this->reset();
        $migrate = $this->migrate();

        return [
            'reset' => $reset,
            'migrate' => $migrate,
        ];
    }

    /**
     * Migration durumunu döner.
     *
     * @return array<array<string, mixed>>
     */
    public function status(): array
    {
        $this->ensureMigrationTableExists();

        $files = $this->getMigrationFiles();
        $ran = $this->getRanMigrationsWithBatch();
        $status = [];

        foreach ($files as $file) {
            $name = $this->getMigrationName($file);
            $isRan = isset($ran[$name]);

            $status[] = [
                'migration' => $name,
                'batch' => $isRan ? $ran[$name]['batch'] : null,
                'status' => $isRan ? 'Ran' : 'Pending',
            ];
        }

        return $status;
    }

    /**
     * Verilen işi bir transaction içinde çalıştırır; hata olursa geri alır.
     *
     * Not: Şema değişikliklerinin geri alınabilmesi yalnızca transactional DDL
     * destekleyen sürücülerde (PostgreSQL, SQLite) geçerlidir. MySQL/MariaDB'de
     * DDL implicit commit yapar; bu sarım INSERT/DELETE kaydını korur ama şemayı
     * geri ALMAZ. Zaten açık bir transaction varsa yenisi başlatılmaz (iç içe begin önlenir).
     */
    private function transactional(callable $work): void
    {
        $ownsTransaction = !$this->pdo()->inTransaction();

        if ($ownsTransaction) {
            $this->pdo()->beginTransaction();
        }

        try {
            $work();

            // MySQL/MariaDB'de DDL (CREATE/ALTER/DROP) implicit commit tetikler ve
            // açık transaction'ı sonlandırır; bu durumda commit() "no active
            // transaction" hatası verir. inTransaction() guard'ı ile yalnızca hâlâ
            // açık bir transaction varsa commit edilir (PG/SQLite'ta her zaman açıktır).
            if ($ownsTransaction && $this->pdo()->inTransaction()) {
                $this->pdo()->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Migration dosyasını çalıştırır.
     */
    private function runMigration(string $migration, string $method): void
    {
        $file = $this->migrationPath . '/' . $migration . '.php';

        if (!file_exists($file)) {
            throw new RuntimeException("Migration dosyası bulunamadı: {$file}");
        }

        $migrationInstance = require $file;

        if (!$migrationInstance instanceof Migration) {
            throw new RuntimeException("Migration sınıfı Migration'dan türetilmeli: {$migration}");
        }

        $migrationInstance->setSchema($this->schema);

        try {
            $migrationInstance->$method();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Migration hatası ({$migration}): " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Migration'ı veritabanına kaydeder.
     */
    private function recordMigration(string $migration, int $batch): void
    {
        $sql = sprintf(
            'INSERT INTO %s (migration, batch) VALUES (:migration, :batch)',
            $this->migrationTableId()
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([
            'migration' => $migration,
            'batch' => $batch,
        ]);
    }

    /**
     * Migration kaydını siler.
     */
    private function removeMigration(string $migration): void
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE migration = :migration',
            $this->migrationTableId()
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['migration' => $migration]);
    }

    /**
     * Migration dosyalarını döner.
     *
     * @return array<string>
     */
    public function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationPath)) {
            return [];
        }

        $files = glob($this->migrationPath . '/*.php');

        if ($files === false) {
            return [];
        }

        $migrations = [];
        foreach ($files as $file) {
            $migrations[] = $this->getMigrationName($file);
        }

        sort($migrations);

        return $migrations;
    }

    /**
     * Dosya yolundan migration adını çıkarır.
     */
    private function getMigrationName(string $file): string
    {
        return basename($file, '.php');
    }

    /**
     * Çalıştırılmış migration'ları döner.
     *
     * @return array<string>
     */
    public function getRanMigrations(): array
    {
        if (!$this->schema->hasTable($this->migrationTable)) {
            return [];
        }

        $sql = sprintf(
            'SELECT migration FROM %s ORDER BY batch ASC, migration ASC',
            $this->migrationTableId()
        );

        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Çalıştırılmış migration'ları batch bilgisiyle döner.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getRanMigrationsWithBatch(): array
    {
        if (!$this->schema->hasTable($this->migrationTable)) {
            return [];
        }

        $sql = sprintf(
            'SELECT migration, batch, executed_at FROM %s ORDER BY batch ASC, migration ASC',
            $this->migrationTableId()
        );

        $results = $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $migrations = [];

        foreach ($results as $row) {
            $migrations[$row['migration']] = $row;
        }

        return $migrations;
    }

    /**
     * Bekleyen migration'ları döner.
     *
     * @return array<string>
     */
    public function getPendingMigrations(): array
    {
        $files = $this->getMigrationFiles();
        $ran = $this->getRanMigrations();

        return array_diff($files, $ran);
    }

    /**
     * Geri alınacak migration'ları döner.
     *
     * @return array<string>
     */
    private function getMigrationsToRollback(int $steps): array
    {
        $sql = sprintf(
            'SELECT migration FROM %s ORDER BY batch DESC, migration DESC LIMIT :limit',
            $this->migrationTableId()
        );

        $stmt = $this->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $steps, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Sonraki batch numarasını döner.
     */
    private function getNextBatchNumber(): int
    {
        $sql = sprintf(
            'SELECT COALESCE(MAX(batch), 0) + 1 FROM %s',
            $this->migrationTableId()
        );

        return (int) $this->pdo()->query($sql)->fetchColumn();
    }

    /**
     * Son batch numarasını döner.
     */
    public function getLastBatchNumber(): int
    {
        $sql = sprintf(
            'SELECT COALESCE(MAX(batch), 0) FROM %s',
            $this->migrationTableId()
        );

        return (int) $this->pdo()->query($sql)->fetchColumn();
    }
}
