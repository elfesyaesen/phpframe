<?php

declare(strict_types=1);

namespace System\Database;

use PDO;

/**
 * Abstract base migration class.
 * Tüm migration'lar bu sınıfı extend eder.
 */
abstract class Migration
{
    protected PDO $pdo;
    protected Schema $schema;

    /**
     * Migration'ı çalıştırır (ileri).
     */
    abstract public function up(): void;

    /**
     * Migration'ı geri alır.
     */
    abstract public function down(): void;

    /**
     * Schema builder'ı set eder.
     */
    public function setSchema(Schema $schema): void
    {
        $this->schema = $schema;
        $this->pdo = $schema->getConnection();
    }

    /**
     * Tablo öneki (`DB_PREFIX` sabitinin yerine).
     *
     * Migration'lar index/foreign-key ADLARINDA öneke ihtiyaç duyar
     * (`'uq_' . $this->prefix() . 'user_email'`); tablo adlarında ise
     * gerekmez — Schema/Blueprint öneki kendisi ekler.
     *
     * Migration'lar container tarafından çözülmediği (MigrationRunner
     * tarafından `new` edildiği) için önek Schema üzerinden alınır.
     */
    protected function prefix(): string
    {
        return $this->schema->prefix();
    }

    /**
     * Raw SQL çalıştırır.
     */
    protected function raw(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * Prepared statement ile SQL çalıştırır.
     *
     * @param array<string, mixed> $params
     */
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Tablo var mı kontrol eder.
     *
     * @param string $table DB_PREFIX'siz tablo adı (ör. 'devices'). Prefix içeride eklenir.
     */
    protected function hasTable(string $table): bool
    {
        return $this->schema->hasTable($table);
    }

    /**
     * Kolon var mı kontrol eder.
     *
     * @param string $table DB_PREFIX'siz tablo adı (ör. 'devices'). Prefix içeride eklenir.
     */
    protected function hasColumn(string $table, string $column): bool
    {
        return $this->schema->hasColumn($table, $column);
    }
}
