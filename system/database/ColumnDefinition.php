<?php

declare(strict_types=1);

namespace System\Database;

/**
 * Kolon tanımlama için fluent interface.
 */
class ColumnDefinition
{
    private string $name;
    private string $type;
    private ?int $length = null;
    private ?int $precision = null;
    private ?int $scale = null;
    private bool $nullable = false;
    private mixed $default = null;
    private bool $hasDefault = false;
    private bool $unsigned = false;
    private bool $autoIncrement = false;
    private ?string $after = null;
    private ?string $comment = null;
    private ?string $charset = null;
    private ?string $collation = null;

    /** @var array<string> */
    private array $enumValues = [];

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function unsigned(bool $value = true): self
    {
        $this->unsigned = $value;
        return $this;
    }

    public function autoIncrement(bool $value = true): self
    {
        $this->autoIncrement = $value;
        return $this;
    }

    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
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

    public function length(int $length): self
    {
        $this->length = $length;
        return $this;
    }

    public function precision(int $precision, int $scale = 0): self
    {
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    /**
     * @param array<string> $values
     */
    public function enumValues(array $values): self
    {
        $this->enumValues = $values;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * SQL kolon tanımını sürücüye göre oluşturur.
     * MySQL ve PostgreSQL hedeflenir; diğer sürücüler ANSI (PG-benzeri) yol kullanır.
     */
    public function toSql(DatabaseDriver $driver): string
    {
        return $driver === DatabaseDriver::MYSQL
            ? $this->toMysqlSql($driver)
            : $this->toAnsiSql($driver);
    }

    /**
     * MySQL kolon grameri (UNSIGNED, AUTO_INCREMENT, inline COMMENT, AFTER).
     */
    private function toMysqlSql(DatabaseDriver $driver): string
    {
        $sql = $driver->quoteIdentifier($this->name) . ' ' . $this->mysqlType();

        if ($this->unsigned && $this->isNumericType()) {
            $sql .= ' UNSIGNED';
        }
        if ($this->charset !== null) {
            $sql .= " CHARACTER SET {$this->charset}";
        }
        if ($this->collation !== null) {
            $sql .= " COLLATE {$this->collation}";
        }

        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';

        if ($this->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }
        if ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $this->formatDefaultValue();
        }
        if ($this->comment !== null) {
            $sql .= " COMMENT '" . addslashes($this->comment) . "'";
        }
        if ($this->after !== null) {
            $sql .= ' AFTER ' . $driver->quoteIdentifier($this->after);
        }

        return $sql;
    }

    /**
     * PostgreSQL (ve ANSI) kolon grameri. PG'de UNSIGNED yoktur; auto-increment
     * SERIAL/BIGSERIAL tipi ile ifade edilir; inline COMMENT/AFTER desteklenmez.
     */
    private function toAnsiSql(DatabaseDriver $driver): string
    {
        // Auto-increment → SERIAL/BIGSERIAL (tip yerine geçer; UNSIGNED/AUTO_INCREMENT eklenmez).
        if ($this->autoIncrement) {
            $serial = $this->type === 'bigInteger' ? 'BIGSERIAL' : 'SERIAL';
            $sql = $driver->quoteIdentifier($this->name) . ' ' . $serial;
            $sql .= $this->nullable ? '' : ' NOT NULL';
            return $sql;
        }

        $sql = $driver->quoteIdentifier($this->name) . ' ' . $this->ansiType();
        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';

        if ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $this->formatDefaultValue();
        }

        return $sql;
    }

    private function mysqlType(): string
    {
        return match ($this->type) {
            'bigInteger' => 'BIGINT' . ($this->length !== null ? "({$this->length})" : ''),
            'integer' => 'INT' . ($this->length !== null ? "({$this->length})" : ''),
            'tinyInteger' => 'TINYINT' . ($this->length !== null ? "({$this->length})" : ''),
            'smallInteger' => 'SMALLINT' . ($this->length !== null ? "({$this->length})" : ''),
            'mediumInteger' => 'MEDIUMINT' . ($this->length !== null ? "({$this->length})" : ''),
            'decimal' => 'DECIMAL(' . ($this->precision ?? 8) . ',' . ($this->scale ?? 2) . ')',
            'float' => 'FLOAT' . ($this->precision !== null ? "({$this->precision},{$this->scale})" : ''),
            'double' => 'DOUBLE' . ($this->precision !== null ? "({$this->precision},{$this->scale})" : ''),
            'string' => 'VARCHAR(' . ($this->length ?? 255) . ')',
            'char' => 'CHAR(' . ($this->length ?? 255) . ')',
            'text' => 'TEXT',
            'mediumText' => 'MEDIUMTEXT',
            'longText' => 'LONGTEXT',
            'boolean' => 'TINYINT(1)',
            'date' => 'DATE',
            'datetime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'year' => 'YEAR',
            'binary' => 'BLOB',
            'json' => 'JSON',
            'enum' => "ENUM('" . implode("','", array_map('addslashes', $this->enumValues)) . "')",
            default => strtoupper($this->type),
        };
    }

    private function ansiType(): string
    {
        return match ($this->type) {
            'bigInteger' => 'BIGINT',
            'integer' => 'INTEGER',
            'tinyInteger' => 'SMALLINT',     // PG'de TINYINT yok
            'smallInteger' => 'SMALLINT',
            'mediumInteger' => 'INTEGER',     // PG'de MEDIUMINT yok
            'decimal' => 'NUMERIC(' . ($this->precision ?? 8) . ',' . ($this->scale ?? 2) . ')',
            'float' => 'REAL',
            'double' => 'DOUBLE PRECISION',
            'string' => 'VARCHAR(' . ($this->length ?? 255) . ')',
            'char' => 'CHAR(' . ($this->length ?? 255) . ')',
            'text', 'mediumText', 'longText' => 'TEXT',  // PG'de tek TEXT tipi
            'boolean' => 'BOOLEAN',
            'date' => 'DATE',
            'datetime' => 'TIMESTAMP',        // PG'de DATETIME yok
            'timestamp' => 'TIMESTAMP',
            'time' => 'TIME',
            'year' => 'SMALLINT',             // PG'de YEAR yok
            'binary' => 'BYTEA',
            'json' => 'JSONB',
            // PG'de native ENUM ayrı bir tip gerektirir; taşınabilirlik için VARCHAR + CHECK.
            'enum' => 'VARCHAR(255) CHECK (' . $this->name . " IN ('"
                . implode("','", array_map('addslashes', $this->enumValues)) . "'))",
            default => strtoupper($this->type),
        };
    }

    private function formatDefaultValue(): string
    {
        if ($this->default === null) {
            return 'NULL';
        }

        if (is_bool($this->default)) {
            return $this->default ? '1' : '0';
        }

        if (is_int($this->default) || is_float($this->default)) {
            return (string) $this->default;
        }

        // CURRENT_TIMESTAMP gibi SQL fonksiyonları
        if (is_string($this->default) && preg_match('/^[A-Z_]+(\(\))?$/', $this->default)) {
            return $this->default;
        }

        return "'" . addslashes((string) $this->default) . "'";
    }

    private function isNumericType(): bool
    {
        return in_array($this->type, [
            'bigInteger',
            'integer',
            'tinyInteger',
            'smallInteger',
            'mediumInteger',
            'decimal',
            'float',
            'double',
        ], true);
    }
}
