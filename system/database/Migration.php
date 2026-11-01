<?php

declare(strict_types=1);

namespace System\Database;

use PDO;

abstract class Migration
{
    protected PDO $pdo;
    protected Schema $schema;

    abstract public function up(): void;

    abstract public function down(): void;

    public function setSchema(Schema $schema): void
    {
        $this->schema = $schema;
        $this->pdo = $schema->getConnection();
    }

    protected function prefix(): string
    {
        return $this->schema->prefix();
    }

    protected function raw(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * @param string $table
     */
    protected function hasTable(string $table): bool
    {
        return $this->schema->hasTable($table);
    }

    /**
     * @param string $table
     */
    protected function hasColumn(string $table, string $column): bool
    {
        return $this->schema->hasColumn($table, $column);
    }
}
