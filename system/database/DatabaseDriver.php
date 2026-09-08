<?php

declare(strict_types=1);

namespace System\Database;

enum DatabaseDriver: string
{
    case MYSQL      = 'mysql';
    case POSTGRESQL = 'pgsql';
    case SQLITE     = 'sqlite';
    case SQLSERVER  = 'sqlsrv';

    public function defaultPort(): int
    {
        return match ($this) {
            self::MYSQL      => 3306,
            self::POSTGRESQL => 5432,
            self::SQLITE     => 0,
            self::SQLSERVER  => 1433,
        };
    }

    public function defaultCharset(): string
    {
        return match ($this) {
            self::MYSQL      => 'utf8mb4',
            self::POSTGRESQL => 'UTF8',
            self::SQLITE     => 'UTF-8',
            self::SQLSERVER  => 'UTF-8',
        };
    }

    /**
     * Identifier'ı (tablo/kolon/index adı) sürücüye göre tırnaklar.
     * MySQL → `backtick`, SQL Server → [bracket], PostgreSQL/SQLite → "ANSI".
     */
    public function quoteIdentifier(string $identifier): string
    {
        return match ($this) {
            self::MYSQL     => "`{$identifier}`",
            self::SQLSERVER => "[{$identifier}]",
            default         => "\"{$identifier}\"",
        };
    }

    public function buildDsn(string $host, string $dbname, int $port, string $charset): string
    {
        return match ($this) {
            self::MYSQL      => "mysql:host={$host};dbname={$dbname};charset={$charset};port={$port}",
            self::POSTGRESQL => "pgsql:host={$host};dbname={$dbname};port={$port};options='--client_encoding={$charset}'",
            self::SQLITE     => "sqlite:{$dbname}",
            self::SQLSERVER  => "sqlsrv:Server={$host},{$port};Database={$dbname}",
        };
    }
}
