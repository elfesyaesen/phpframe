<?php

declare(strict_types=1);

namespace System\Database;

use Closure;
use System\Config\DatabaseConfig;
use PDO;

class Schema
{
    private readonly DatabaseDriver $driver;

    private readonly string $prefix;

    public function __construct(
        private readonly Database $database,
        DatabaseConfig $config,
    ) {
        $this->driver = $config->driver;
        $this->prefix = $config->prefix;
    }

    private function pdo(): PDO
    {
        return $this->database->getConnection();
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function create(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table, creating: true, prefix: $this->prefix);
        $callback($blueprint);

        foreach ($blueprint->toCreateStatements($this->driver) as $sql) {
            $this->pdo()->exec($sql);
        }
    }

    public function table(string $table, Closure $callback): void
    {
        $blueprint = new Blueprint($table, creating: false, prefix: $this->prefix);
        $callback($blueprint);

        foreach ($blueprint->toAlterSql($this->driver) as $sql) {
            $this->pdo()->exec($sql);
        }
    }

    public function drop(string $table): void
    {
        $blueprint = new Blueprint($table, prefix: $this->prefix);
        $this->pdo()->exec($blueprint->toDropSql($this->driver));
    }

    public function dropIfExists(string $table): void
    {
        $blueprint = new Blueprint($table, prefix: $this->prefix);
        $this->pdo()->exec($blueprint->toDropIfExistsSql($this->driver));
    }

    public function rename(string $from, string $to): void
    {
        $fromTable = $this->prefix . $from;
        $toTable = $this->prefix . $to;

        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => "ALTER TABLE \"{$fromTable}\" RENAME TO \"{$toTable}\"",
            default                    => "RENAME TABLE `{$fromTable}` TO `{$toTable}`",
        };

        $this->pdo()->exec($sql);
    }

    public function hasTable(string $table): bool
    {
        $tableName = $this->prefix . $table;

        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :table_name",
            default                    => "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name",
        };

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['table_name' => $tableName]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $tableName = $this->prefix . $table;

        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name",
            default                    => "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name",
        };

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['table_name' => $tableName, 'column_name' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string> $columns
     */
    public function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!$this->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string>
     */
    public function getColumnListing(string $table): array
    {
        $tableName = $this->prefix . $table;

        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name ORDER BY ordinal_position",
            default                    => "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name ORDER BY ordinal_position",
        };

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['table_name' => $tableName]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getColumnType(string $table, string $column): ?string
    {
        $tableName = $this->prefix . $table;

        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => "SELECT data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name",
            default                    => "SELECT data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name",
        };

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(['table_name' => $tableName, 'column_name' => $column]);

        $result = $stmt->fetchColumn();

        return $result !== false ? (string) $result : null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getIndexes(string $table): array
    {
        $tableName = $this->prefix . $table;

        if ($this->driver === DatabaseDriver::POSTGRESQL) {
            $sql = "SELECT indexname AS index_name, indexdef AS index_definition
                    FROM pg_indexes
                    WHERE schemaname = 'public' AND tablename = :table_name";
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute(['table_name' => $tableName]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = sprintf('SHOW INDEX FROM `%s`', $tableName);
        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getForeignKeys(string $table): array
    {
        $tableName = $this->prefix . $table;

        if ($this->driver === DatabaseDriver::POSTGRESQL) {
            $sql = "SELECT
                        tc.constraint_name,
                        kcu.column_name,
                        ccu.table_name AS referenced_table_name,
                        ccu.column_name AS referenced_column_name
                    FROM information_schema.table_constraints tc
                    JOIN information_schema.key_column_usage kcu
                        ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                    JOIN information_schema.constraint_column_usage ccu
                        ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                    WHERE tc.constraint_type = 'FOREIGN KEY'
                        AND tc.table_schema = 'public'
                        AND tc.table_name = :table_name";
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute(['table_name' => $tableName]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $sql = sprintf(
            "SELECT
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '%s'
                AND REFERENCED_TABLE_NAME IS NOT NULL",
            $tableName
        );

        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string>
     */
    public function getAllTables(): array
    {
        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'",
            default                    => "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()",
        };

        return $this->pdo()->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function disableForeignKeyConstraints(): void
    {
        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => 'SET session_replication_role = replica',
            default                    => 'SET FOREIGN_KEY_CHECKS=0',
        };

        $this->pdo()->exec($sql);
    }

    public function enableForeignKeyConstraints(): void
    {
        $sql = match ($this->driver) {
            DatabaseDriver::POSTGRESQL => 'SET session_replication_role = DEFAULT',
            default                    => 'SET FOREIGN_KEY_CHECKS=1',
        };

        $this->pdo()->exec($sql);
    }

    public function raw(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    public function getConnection(): PDO
    {
        return $this->pdo();
    }
}
