<?php

declare(strict_types=1);

namespace System\Database;

/**
 * Tablo şeması tanımlama sınıfı.
 */
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
     * @param string $prefix Tablo öneki. Eskiden `DB_PREFIX` global sabiti
     *        okunuyordu; artık Schema tarafından geçilir. Blueprint bir DSL
     *        objesi olduğu ve container tarafından çözülmediği için önek
     *        constructor'dan gelmek zorunda — sabit okumak, "hangi önek
     *        kullanılıyor" sorusunu config'in yüklenip yüklenmediğine
     *        bağlıyordu.
     */
    public function __construct(string $table, bool $creating = true, private readonly string $prefix = '')
    {
        $this->table = $table;
        $this->creating = $creating;
    }

    // ─────────────────────────────────────────────────────────────
    // Kolon Tipleri
    // ─────────────────────────────────────────────────────────────

    /**
     * Auto-incrementing UNSIGNED BIGINT (primary key).
     */
    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($column);
    }

    /**
     * Auto-incrementing UNSIGNED BIGINT.
     */
    public function bigIncrements(string $column): ColumnDefinition
    {
        $this->primaryKeyColumns = [$column];
        return $this->addColumn($column, 'bigInteger')
            ->unsigned()
            ->autoIncrement();
    }

    /**
     * Auto-incrementing UNSIGNED INTEGER.
     */
    public function increments(string $column): ColumnDefinition
    {
        $this->primaryKeyColumns = [$column];
        return $this->addColumn($column, 'integer')
            ->unsigned()
            ->autoIncrement();
    }

    /**
     * BIGINT.
     */
    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'bigInteger');
    }

    /**
     * INTEGER.
     */
    public function integer(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'integer');
    }

    /**
     * TINYINT.
     */
    public function tinyInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'tinyInteger');
    }

    /**
     * SMALLINT.
     */
    public function smallInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'smallInteger');
    }

    /**
     * MEDIUMINT.
     */
    public function mediumInteger(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'mediumInteger');
    }

    /**
     * UNSIGNED BIGINT (foreign key için).
     */
    public function unsignedBigInteger(string $column): ColumnDefinition
    {
        return $this->bigInteger($column)->unsigned();
    }

    /**
     * UNSIGNED INTEGER (foreign key için).
     */
    public function unsignedInteger(string $column): ColumnDefinition
    {
        return $this->integer($column)->unsigned();
    }

    /**
     * DECIMAL.
     */
    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($column, 'decimal')->precision($precision, $scale);
    }

    /**
     * FLOAT.
     */
    public function float(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($column, 'float')->precision($precision, $scale);
    }

    /**
     * DOUBLE.
     */
    public function double(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn($column, 'double')->precision($precision, $scale);
    }

    /**
     * VARCHAR.
     */
    public function string(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($column, 'string')->length($length);
    }

    /**
     * CHAR.
     */
    public function char(string $column, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($column, 'char')->length($length);
    }

    /**
     * TEXT.
     */
    public function text(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'text');
    }

    /**
     * MEDIUMTEXT.
     */
    public function mediumText(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'mediumText');
    }

    /**
     * LONGTEXT.
     */
    public function longText(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'longText');
    }

    /**
     * BOOLEAN (TINYINT(1)).
     */
    public function boolean(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'boolean');
    }

    /**
     * DATE.
     */
    public function date(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'date');
    }

    /**
     * DATETIME.
     */
    public function datetime(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'datetime');
    }

    /**
     * TIMESTAMP.
     */
    public function timestamp(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'timestamp');
    }

    /**
     * TIME.
     */
    public function time(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'time');
    }

    /**
     * YEAR.
     */
    public function year(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'year');
    }

    /**
     * BLOB.
     */
    public function binary(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'binary');
    }

    /**
     * JSON.
     */
    public function json(string $column): ColumnDefinition
    {
        return $this->addColumn($column, 'json');
    }

    /**
     * ENUM.
     *
     * @param array<string> $values
     */
    public function enum(string $column, array $values): ColumnDefinition
    {
        return $this->addColumn($column, 'enum')->enumValues($values);
    }

    /**
     * created_at ve updated_at TIMESTAMP kolonları.
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable()->default('CURRENT_TIMESTAMP');
        $this->timestamp('updated_at')->nullable()->default('CURRENT_TIMESTAMP');
    }

    /**
     * Soft delete için deleted_at TIMESTAMP kolonu.
     */
    public function softDeletes(string $column = 'deleted_at'): ColumnDefinition
    {
        return $this->timestamp($column)->nullable();
    }

    // ─────────────────────────────────────────────────────────────
    // Index & Keys
    // ─────────────────────────────────────────────────────────────

    /**
     * Primary key tanımlar.
     *
     * @param string|array<string> $columns
     */
    public function primary(string|array $columns, ?string $name = null): self
    {
        // $name (özel PK constraint adı) şu an CREATE çıktısında kullanılmıyor;
        // PRIMARY KEY (...) primaryKeyColumns'tan üretilir. İmza geriye dönük korunur.
        $this->primaryKeyColumns = (array) $columns;
        return $this;
    }

    /**
     * Unique index tanımlar.
     *
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
     * Index tanımlar.
     *
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
     * Fulltext index tanımlar.
     *
     * @param string|array<string> $columns
     */
    public function fulltext(string|array $columns, ?string $name = null): self
    {
        $columns = (array) $columns;
        $name = $name ?? $this->table . '_' . implode('_', $columns) . '_fulltext';
        $this->indexes[$name] = ['type' => 'FULLTEXT', 'columns' => $columns];
        return $this;
    }

    /**
     * Foreign key tanımlar.
     */
    public function foreign(string $column): ForeignKeyDefinition
    {
        $foreignKey = new ForeignKeyDefinition($column, $this->prefix);
        $this->foreignKeys[] = $foreignKey;
        return $foreignKey;
    }

    /**
     * Foreign key kolonu oluşturur ve foreign key tanımlar.
     * Kullanım: $table->foreignId('user_id')->constrained();
     */
    public function foreignId(string $column): ColumnDefinition
    {
        return $this->unsignedBigInteger($column);
    }

    // ─────────────────────────────────────────────────────────────
    // Alterations (Değişiklikler)
    // ─────────────────────────────────────────────────────────────

    /**
     * Kolon siler.
     *
     * @param string|array<string> $columns
     */
    public function dropColumn(string|array $columns): void
    {
        $this->dropColumns = array_merge($this->dropColumns, (array) $columns);
    }

    /**
     * Kolon adını değiştirir.
     */
    public function renameColumn(string $from, string $to): void
    {
        $this->renameColumns[$from] = $to;
    }

    /**
     * Index siler.
     */
    public function dropIndex(string $name): void
    {
        $this->dropIndexes[] = $name;
    }

    /**
     * Unique index siler.
     */
    public function dropUnique(string $name): void
    {
        $this->dropIndex($name);
    }

    /**
     * Foreign key siler.
     */
    public function dropForeign(string $name): void
    {
        $this->dropForeignKeys[] = $name;
    }

    /**
     * Primary key siler.
     */
    public function dropPrimary(): void
    {
        $this->rawStatements[] = 'DROP PRIMARY KEY';
    }

    /**
     * Timestamps kolonlarını siler.
     */
    public function dropTimestamps(): void
    {
        $this->dropColumn(['created_at', 'updated_at']);
    }

    /**
     * Soft deletes kolonunu siler.
     */
    public function dropSoftDeletes(string $column = 'deleted_at'): void
    {
        $this->dropColumn($column);
    }

    // ─────────────────────────────────────────────────────────────
    // Table Options
    // ─────────────────────────────────────────────────────────────

    /**
     * Tablo engine'ini belirler.
     */
    public function engine(string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    /**
     * Tablo charset'ini belirler.
     */
    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    /**
     * Tablo collation'ını belirler.
     */
    public function collation(string $collation): self
    {
        $this->collation = $collation;
        return $this;
    }

    // ─────────────────────────────────────────────────────────────
    // SQL Generation
    // ─────────────────────────────────────────────────────────────

    /**
     * CREATE TABLE (+ ayrı CREATE INDEX) ifadelerini sürücüye göre üretir.
     *
     * Index'ler CREATE TABLE'a gömülmez; ayrı CREATE INDEX olarak döner. Sebep:
     * PostgreSQL inline INDEX'i desteklemez. Ayrı CREATE INDEX her iki sürücüde de
     * geçerlidir. Tablo opsiyonları (ENGINE/CHARSET/COLLATE) yalnızca MySQL'e eklenir.
     *
     * @return array<string> [CREATE TABLE, CREATE INDEX, ...]
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
     * Tek bir CREATE INDEX ifadesi üretir (her iki sürücüde taşınabilir).
     *
     * @param array{type: string, columns: array<string>} $index
     */
    private function buildCreateIndex(DatabaseDriver $driver, string $name, array $index, string $quotedTable): string
    {
        $cols = implode(', ', array_map($driver->quoteIdentifier(...), $index['columns']));

        $keyword = match ($index['type']) {
            'UNIQUE'   => 'CREATE UNIQUE INDEX',
            // PG'de FULLTEXT INDEX söz dizimi yoktur (GIN/tsvector paradigması);
            // taşınabilirlik için düz index'e degrade edilir.
            'FULLTEXT' => $driver === DatabaseDriver::MYSQL ? 'CREATE FULLTEXT INDEX' : 'CREATE INDEX',
            default    => 'CREATE INDEX',
        };

        return sprintf('%s %s ON %s (%s)', $keyword, $driver->quoteIdentifier($name), $quotedTable, $cols);
    }

    /**
     * ALTER TABLE SQL'lerini sürücüye göre oluşturur.
     *
     * @return array<string>
     */
    public function toAlterSql(DatabaseDriver $driver): array
    {
        $tableName = $this->prefix . $this->table;
        $quotedTable = $driver->quoteIdentifier($tableName);
        $statements = [];

        // Kolon ekleme
        foreach ($this->columns as $column) {
            $statements[] = sprintf('ALTER TABLE %s ADD COLUMN %s', $quotedTable, $column->toSql($driver));
        }

        // Kolon silme
        foreach ($this->dropColumns as $column) {
            $statements[] = sprintf('ALTER TABLE %s DROP COLUMN %s', $quotedTable, $driver->quoteIdentifier($column));
        }

        // Kolon yeniden adlandırma (MySQL 8+ ve PG: RENAME COLUMN)
        foreach ($this->renameColumns as $from => $to) {
            $statements[] = sprintf(
                'ALTER TABLE %s RENAME COLUMN %s TO %s',
                $quotedTable, $driver->quoteIdentifier($from), $driver->quoteIdentifier($to)
            );
        }

        // Index ekleme — ayrı CREATE INDEX (PG ALTER TABLE ADD INDEX desteklemez)
        foreach ($this->indexes as $name => $index) {
            $statements[] = $this->buildCreateIndex($driver, $name, $index, $quotedTable);
        }

        // Index silme — MySQL: ALTER TABLE ... DROP INDEX; PG: DROP INDEX (standalone)
        foreach ($this->dropIndexes as $name) {
            $statements[] = $driver === DatabaseDriver::MYSQL
                ? sprintf('ALTER TABLE %s DROP INDEX %s', $quotedTable, $driver->quoteIdentifier($name))
                : sprintf('DROP INDEX %s', $driver->quoteIdentifier($name));
        }

        // Foreign key ekleme
        foreach ($this->foreignKeys as $fk) {
            $statements[] = $fk->toAddSql($this->table, $driver);
        }

        // Foreign key silme — MySQL: DROP FOREIGN KEY; PG: DROP CONSTRAINT
        foreach ($this->dropForeignKeys as $name) {
            $dropClause = $driver === DatabaseDriver::MYSQL ? 'DROP FOREIGN KEY' : 'DROP CONSTRAINT';
            $statements[] = sprintf('ALTER TABLE %s %s %s', $quotedTable, $dropClause, $driver->quoteIdentifier($name));
        }

        // Raw statements (escape hatch — sürücüye özgü olabilir)
        foreach ($this->rawStatements as $raw) {
            $statements[] = sprintf('ALTER TABLE %s %s', $quotedTable, $raw);
        }

        return $statements;
    }

    /**
     * DROP TABLE SQL'i oluşturur.
     */
    public function toDropSql(DatabaseDriver $driver): string
    {
        return sprintf('DROP TABLE %s', $driver->quoteIdentifier($this->prefix . $this->table));
    }

    /**
     * DROP TABLE IF EXISTS SQL'i oluşturur.
     */
    public function toDropIfExistsSql(DatabaseDriver $driver): string
    {
        return sprintf('DROP TABLE IF EXISTS %s', $driver->quoteIdentifier($this->prefix . $this->table));
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

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
