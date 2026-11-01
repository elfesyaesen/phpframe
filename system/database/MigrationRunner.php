<?php

declare(strict_types=1);

namespace System\Database;

use PDO;
use System\Config\AppConfig;
use System\Config\DatabaseConfig;
use RuntimeException;

class MigrationRunner
{
    public const DIRECTORY = 'app/migration';

    private readonly Schema $schema;
    private readonly DatabaseDriver $driver;
    private readonly string $prefix;
    private string $migrationPath;
    private string $migrationTable;

    public function __construct(
        private readonly Database $database,
        Schema $schema,
        DatabaseConfig $config,
        AppConfig $app,
    ) {
        $this->schema = $schema;
        $this->driver = $config->driver;
        $this->prefix = $config->prefix;
        $this->migrationPath = $app->path(...explode('/', self::DIRECTORY));
        $this->migrationTable = 'migrations';
    }

    private function pdo(): PDO
    {
        return $this->database->getConnection();
    }

    private function q(string $identifier): string
    {
        return match ($this->driver) {
            DatabaseDriver::MYSQL     => "`{$identifier}`",
            DatabaseDriver::SQLSERVER => "[{$identifier}]",
            default                   => "\"{$identifier}\"",
        };
    }

    private function migrationTableId(): string
    {
        return $this->q($this->prefix . $this->migrationTable);
    }

    public function setMigrationPath(string $path): void
    {
        $this->migrationPath = $path;
    }

    public function ensureMigrationTableExists(): void
    {
        if (!$this->schema->hasTable($this->migrationTable)) {
            $this->createMigrationTable();
        }
    }

    private function createMigrationTable(): void
    {
        $table = $this->migrationTableId();

        $autoPk = match ($this->driver) {
            DatabaseDriver::MYSQL      => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            DatabaseDriver::SQLITE     => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            DatabaseDriver::SQLSERVER  => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
            DatabaseDriver::POSTGRESQL => 'BIGSERIAL PRIMARY KEY',
        };

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
     * @return array<string>
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
     * @param int|null $steps
     * @return array<string>
     */
    public function rollback(?int $steps = null): array
    {
        $this->ensureMigrationTableExists();

        $migrations = $this->getMigrationsToRollback($steps);

        if (empty($migrations)) {
            return [];
        }

        $this->assertFilesExist($migrations);

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
     * @return array<string>
     */
    public function reset(): array
    {
        $this->ensureMigrationTableExists();

        $migrations = $this->getRanMigrations();

        if (empty($migrations)) {
            return [];
        }

        $migrations = array_reverse($migrations);
        $this->assertFilesExist($migrations);
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
     * @return array<string, array<string>>
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

    private function transactional(callable $work): void
    {
        $ownsTransaction = !$this->pdo()->inTransaction();

        if ($ownsTransaction) {
            $this->pdo()->beginTransaction();
        }

        try {
            $work();

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

    private function getMigrationName(string $file): string
    {
        return basename($file, '.php');
    }

    /**
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
     * @return array<string>
     */
    public function getPendingMigrations(): array
    {
        $files = $this->getMigrationFiles();
        $ran = $this->getRanMigrations();

        return array_diff($files, $ran);
    }

    /**
     * @return array<string>
     */
    private function getMigrationsToRollback(?int $steps): array
    {
        $sql = sprintf(
            'SELECT migration, batch FROM %s ORDER BY batch DESC, migration DESC',
            $this->migrationTableId()
        );

        $rows = $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        if ($steps !== null) {
            return array_column(array_slice($rows, 0, $steps), 'migration');
        }

        $lastBatch = $rows[0]['batch'];

        return array_column(
            array_filter($rows, static fn(array $row): bool => $row['batch'] == $lastBatch),
            'migration'
        );
    }

    /**
     * @param array<string> $migrations
     */
    private function assertFilesExist(array $migrations): void
    {
        $missing = array_filter(
            $migrations,
            fn(string $migration): bool => !file_exists($this->migrationPath . '/' . $migration . '.php')
        );

        if ($missing !== []) {
            throw new RuntimeException(
                "Migration dosyası bulunamadı, hiçbir şey geri alınmadı:\n  - "
                . implode("\n  - ", $missing)
            );
        }
    }

    private function getNextBatchNumber(): int
    {
        $sql = sprintf(
            'SELECT COALESCE(MAX(batch), 0) + 1 FROM %s',
            $this->migrationTableId()
        );

        return (int) $this->pdo()->query($sql)->fetchColumn();
    }

    public function getLastBatchNumber(): int
    {
        $sql = sprintf(
            'SELECT COALESCE(MAX(batch), 0) FROM %s',
            $this->migrationTableId()
        );

        return (int) $this->pdo()->query($sql)->fetchColumn();
    }
}
