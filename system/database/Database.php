<?php

declare(strict_types=1);

namespace System\Database;

use PDO;
use PDOException;
use System\Config\DatabaseConfig;
use System\Config\MonitorConfig;
use System\Exceptions\InternalServerException;
use System\Monitor\Instrumentation\InstrumentedPdo;
use System\Monitor\Instrumentation\InstrumentedStatement;
use System\Monitor\RecorderLocator;
use Throwable;

class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly RecorderLocator $recorder,
        private readonly MonitorConfig $monitor,
    ) {
    }

    public function getConnection(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->getConnection();

        if ($pdo->inTransaction()) {
            throw new InternalServerException(
                message: 'İç içe transaction desteklenmiyor',
                context: ['hint' => 'Dıştaki transaction zaten açık; iç çağrıyı transaction dışına alın.']
            );
        }

        $pdo->beginTransaction();

        try {
            $result = $fn();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        $pdo->commit();

        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->pdo !== null && $this->pdo->inTransaction();
    }

    public function getDriver(): DatabaseDriver
    {
        return $this->config->driver;
    }

    public function getConfig(): DatabaseConfig
    {
        return $this->config;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function isInstrumented(): bool
    {
        return $this->monitor->instrumentsQueries($this->config);
    }

    private function connect(): PDO
    {
        $dsn = $this->config->dsn();
        $options = $this->config->pdoOptions();
        $instrumented = $this->isInstrumented();

        if ($instrumented) {
            $options[PDO::ATTR_STATEMENT_CLASS] = [
                InstrumentedStatement::class,
                [$this->recorder],
            ];
        }

        try {
            return $instrumented
                ? new InstrumentedPdo(
                    $dsn,
                    $this->config->user,
                    $this->config->password,
                    $options,
                    $this->recorder
                )
                : new PDO($dsn, $this->config->user, $this->config->password, $options);
        } catch (PDOException $e) {
            throw new InternalServerException(
                message: 'Veritabanı bağlantı hatası',
                context: [...$this->config->toSafeContext(), 'error' => $e->getMessage()],
                previous: $e
            );
        }
    }
}
