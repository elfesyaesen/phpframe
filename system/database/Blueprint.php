<?php

declare(strict_types=1);

namespace System\Database;

class Blueprint
{
    private string $table;
    private bool $creating;

    /** @var array<ColumnDefinition> */
    private array $columns = [];

    /** @var array<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var array<string, array<string>> */
    private array $indexes = [];

    /** @var array<string> */
    private array $dropColumns = [];

    /** @var array<string, string> */
    private array $renameColumns = [];

    /** @var array<string> */
    private array $dropIndexes = [];

    /** @var array<string> */
    private array $dropForeignKeys = [];

    /** @var array<string> */
    private array $rawStatements = [];

    /** @var array<string> */
    private array $primaryKeyColumns = [];

    private string $engine = 'InnoDB';
    private string $charset = 'utf8mb4';
    private string $collation = 'utf8mb4_unicode_ci';

    /**
     * @param string $prefix
     */
    public function __construct(string $table, bool $creating = true, private readonly string $prefix = '')
    {
        $this->table = $table;
        $this->creating = $creating;
    }

    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($column);
    }

    public function bigIncrements(string $column): ColumnDefinition
    {
        $this->primaryKeyColumns = [$column];
        return $this->addColumn($column, 'bigInteger')
            ->unsigned()
            ->autoIncrement();
    }

    public function increments(string $column): ColumnDefinition
    {
        $this->primaryKeyColumns = [$column];
        return $this->addColumn($column, 'integer')
            ->unsigned()
            ->autoIncrement();
    }

    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'bigInteger');
    }

    public function integer(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'integer');
    }

    public function tinyInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'tinyInteger');
    }

    public function smallInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'smallInteger');
    }

    public function mediumInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'mediumInteger');
    }

    public function unsignedBigInteger(string $column): ColumnDefinition
    {
        return $this->bigInteger($column)->unsigned();
    }

    public function unsignedInteger(string $column): ColumnDefinition
    {
        return $this->integer($column)->unsigned();
    }

    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($column, 'decimal')->precision($precision, $scale);
    }

    public function float(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($column, 'float')->precision($precision, $scale);
    }

    public function double(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($column, 'double')->precision($precision, $scale);
    }

    public function string(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($column, 'string')->length($length);
    }

    public function char(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($column, 'char')->length($length);
    }

    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'text');
    }

    public function mediumText(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'mediumText');
    }

    public function longText(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'longText');
    }

    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'boolean');
    }

    public function date(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'date');
    }

    public function datetime(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'datetime');
    }

    public function timestamp(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'timestamp');
    }

    public function time(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'time');
    }

    public function year(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'year');
    }

    public function binary(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'binary');
    }

    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'json');
    }

    /**
     * @param array<string> $values
     */
    public function enum(string $column, array $values): ColumnDefinition
    {
        return $this->addColumn($column, 'enum')->enumValues($values);
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        $this->timestamp('updated_at')->nullable()->default('CURRENT_TIMESTAMP');
    }

    public function softDeletes(string $column = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($column)->nullable();
    }

    /**
     * @param string|array<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        $this->primaryKeyColumns = (array) $columns;
        return $this;
    }

    /**
     * @param string|array<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $columns = (array) $columns;
        $name = $name ?? $this->table . '_' . implode('_', $columns) . '_unique';
        $this->indexes[$name] = ['type' => 'UNIQUE', 'columns' => $columns];
        return $this;
    }

    /**
     * @param string|array<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $columns = (array) $columns;
        $name = $name ?? $this->table . '_' . implode('_', $columns) . '_index';
        $this->indexes[$name] = ['type' => 'INDEX', 'columns' => $columns];
        return $this;
    }

    /**
     * @param string|array<string> $columns
     */
    public function fulltext(string|array $columns, ?string $name = null): self
    {
        $columns = (array) $columns;
        $name = $name ?? $this->table . '_' . implode('_', $columns) . '_fulltext';
        $this->indexes[$name] = ['type' => 'FULLTEXT', 'columns' => $columns];
        return $this;
    }

    public function foreign(string $column): ForeignKeyDefinition
    {
        $foreignKey = new ForeignKeyDefinition($column, $this->prefix);
        $this->foreignKeys[] = $foreignKey;
        return $foreignKey;
    }

    public function foreignId(string $column): ColumnDefinition
    {
        return $this->unsignedBigInteger($column);
    }

    /**
     * @param string|array<string> $columns
     */
    public function dropColumn(string|array $columns): void
    {
        $this->dropColumns = array_merge($this->dropColumns, (array) $columns);
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->renameColumns[$from] = $to;
    }

    public function dropIndex(string $name): void
    {
        $this->dropIndexes[] = $name;
    }

    public function dropUnique(string $name): void
    {
        $this->dropIndex($name);
    }

    public function dropForeign(string $name): void
    {
        $this->dropForeignKeys[] = $name;
    }

    public function dropPrimary(): void
    {
        $this->rawStatements[] = 'DROP PRIMARY KEY';
    }

    public function dropTimestamps(): void
    {
        $this->dropColumn(['created_at', 'updated_at']);
    }

    public function dropSoftDeletes(string $column = 'deleted_at'): void
    {
        $this->dropColumn($column);
    }

    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function toCreateStatements(DatabaseDriver $driver): array
    {
        $tableName = $this->prefix . $this->table;
        $quotedTable = $driver->quoteIdentifier($tableName);
        $parts = [];

        foreach ($this->columns as $column) {
            $parts[] = $column->toSql($driver);
        }

        if (!empty($this->primaryKeyColumns)) {
            $cols = implode(', ', array_map($driver->quoteIdentifier(...), $this->primaryKeyColumns));
            $parts[] = "PRIMARY KEY ({$cols})";
        }

        foreach ($this->foreignKeys as $fk) {
            $parts[] = $fk->toSql($this->table, $driver);
        }

        $columnsSql = implode(",\n    ", $parts);

        if ($driver === DatabaseDriver::MYSQL) {
            $create = sprintf(
                "CREATE TABLE %s (\n    %s\n) ENGINE=%s DEFAULT CHARSET=%s COLLATE=%s",
                $quotedTable, $columnsSql, $this->engine, $this->charset, $this->collation
            );
        } else {
            $create = sprintf("CREATE TABLE %s (\n    %s\n)", $quotedTable, $columnsSql);
        }

        $statements = [$create];

        foreach ($this->indexes as $name => $index) {
            $statements[] = $this->buildCreateIndex($driver, $name, $index, $quotedTable);
        }

        return $statements;
    }

    /**
     * @param array{type: string, columns: array<string>} $index
     */
    private function buildCreateIndex(DatabaseDriver $driver, string $name, array $index, string $quotedTable): string
    {
        $cols = implode(', ', array_map($driver->quoteIdentifier(...), $index['columns']));

        $keyword = match ($index['type']) {
            'UNIQUE'   => 'CREATE UNIQUE INDEX',

            'FULLTEXT' => $driver === DatabaseDriver::MYSQL ? 'CREATE FULLTEXT INDEX' : 'CREATE INDEX',
            default    => 'CREATE INDEX',
        };

        return sprintf('%s %s ON %s (%s)', $keyword, $driver->quoteIdentifier($name), $quotedTable, $cols);
    }

    /**
     * @return array<string>
     */
    public function toAlterSql(DatabaseDriver $driver): array
    {
        $tableName = $this->prefix . $this->table;
        $quotedTable = $driver->quoteIdentifier($tableName);
        $statements = [];

        foreach ($this->columns as $column) {
            $statements[] = sprintf('ALTER TABLE %s ADD COLUMN %s', $quotedTable, $column->toSql($driver));
        }

        foreach ($this->dropColumns as $column) {
            $statements[] = sprintf('ALTER TABLE %s DROP COLUMN %s', $quotedTable, $driver->quoteIdentifier($column));
        }

        foreach ($this->renameColumns as $from => $to) {
            $statements[] = sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $quotedTable, $driver->quoteIdentifier($from), $driver->quoteIdentifier($to)
            );
        }

        foreach ($this->indexes as $name => $index) {
            $statements[] = $this->buildCreateIndex($driver, $name, $index, $quotedTable);
        }

        foreach ($this->dropIndexes as $name) {
            $statements[] = $driver === DatabaseDriver::MYSQL
                ? sprintf('ALTER TABLE %s DROP INDEX %s', $quotedTable, $driver->quoteIdentifier($name))
                : sprintf('DROP INDEX %s', $driver->quoteIdentifier($name));
        }

        foreach ($this->foreignKeys as $fk) {
            $statements[] = $fk->toAddSql($this->table, $driver);
        }

        foreach ($this->dropForeignKeys as $name) {
            $dropClause = $driver === DatabaseDriver::MYSQL ? 'DROP FOREIGN KEY' : 'DROP CONSTRAINT';
            $statements[] = sprintf('ALTER TABLE %s %s %s', $quotedTable, $dropClause, $driver->quoteIdentifier($name));
        }

        foreach ($this->rawStatements as $raw) {
            $statements[] = sprintf('ALTER TABLE %s %s', $quotedTable, $raw);
        }

        return $statements;
    }

    public function toDropSql(DatabaseDriver $driver): string
    {
        return sprintf('DROP TABLE %s', $driver->quoteIdentifier($this->prefix . $this->table));
    }

    public function toDropIfExistsSql(DatabaseDriver $driver): string
    {
        return sprintf('DROP TABLE IF EXISTS %s', $driver->quoteIdentifier($this->prefix . $this->table));
    }

    private function addColumn(string $name, string $type): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type);
        $this->columns[] = $column;
        return $column;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function isCreating(): bool
    {
        return $this->creating;
    }

    /**
     * @return array<ColumnDefinition>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }
}
