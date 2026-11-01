<?php

declare(strict_types=1);

namespace System\Database;

class ForeignKeyDefinition
{
    private string $column;
    private ?string $referencedTable = null;
    private ?string $referencedColumn = null;
    private string $onDelete = 'RESTRICT';
    private string $onUpdate = 'RESTRICT';
    private ?string $name = null;

    public function __construct(string $column, private readonly string $prefix = '')
    {
        $this->column = $column;
    }

    public function references(string $column): self
    {
        $this->referencedColumn = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->referencedTable = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('CASCADE');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('RESTRICT');
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function getReferencedTable(): ?string
    {
        return $this->referencedTable;
    }

    public function getReferencedColumn(): ?string
    {
        return $this->referencedColumn;
    }

    public function getConstraintName(string $tableName): string
    {
        if ($this->name !== null) {
            return $this->name;
        }

        return $tableName . '_' . $this->column . '_foreign';
    }

    public function toSql(string $tableName, DatabaseDriver $driver): string
    {
        if ($this->referencedTable === null || $this->referencedColumn === null) {
            throw new \RuntimeException(
                "Foreign key için references() ve on() metodları çağrılmalıdır."
            );
        }

        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            $driver->quoteIdentifier($this->getConstraintName($tableName)),
            $driver->quoteIdentifier($this->column),
            $driver->quoteIdentifier($this->prefix . $this->referencedTable),
            $driver->quoteIdentifier($this->referencedColumn),
            $this->onDelete,
            $this->onUpdate
        );
    }

    public function toAddSql(string $tableName, DatabaseDriver $driver): string
    {
        return sprintf(
            'ALTER TABLE %s ADD %s',
            $driver->quoteIdentifier($this->prefix . $tableName),
            $this->toSql($tableName, $driver)
        );
    }

    public function toDropSql(string $tableName, DatabaseDriver $driver): string
    {
        $dropClause = $driver === DatabaseDriver::MYSQL ? 'DROP FOREIGN KEY' : 'DROP CONSTRAINT';

        return sprintf(
            'ALTER TABLE %s %s %s',
            $driver->quoteIdentifier($this->prefix . $tableName),
            $dropClause,
            $driver->quoteIdentifier($this->getConstraintName($tableName))
        );
    }
}
