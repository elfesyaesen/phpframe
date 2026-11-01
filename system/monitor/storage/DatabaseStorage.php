<?php

declare(strict_types=1);

namespace System\Monitor\Storage;

use Closure;
use DateTimeImmutable;
use PDO;
use System\Database\Database;
use System\Database\DatabaseDriver;
use System\Logging\Contracts\LoggerInterface;
use System\Monitor\Contracts\StorageInterface;
use System\Monitor\RequestTrace;
use Throwable;

final class DatabaseStorage implements StorageInterface
{
    public const TABLE_REQUEST   = 'monitor_request';
    public const TABLE_QUERY     = 'monitor_query';
    public const TABLE_EXCEPTION = 'monitor_exception';

    /**
     * @param Closure(): Database $databaseProvider
     */
    public function __construct(
        private readonly Closure $databaseProvider,
        private readonly LoggerInterface $logger,
    ) {}

    public function store(RequestTrace $trace): bool
    {
        $pdo = null;

        try {
            $database = ($this->databaseProvider)();
            $pdo      = $database->getConnection();

            $pdo->beginTransaction();

            $this->insertRequest($database, $pdo, $trace);
            $this->insertQueries($database, $pdo, $trace);
            $this->insertExceptions($database, $pdo, $trace);

            $pdo->commit();

            return true;
        } catch (Throwable $e) {
            $this->rollbackQuietly($pdo);

            $this->logger->warning('Monitor kaydı yazılamadı', [
                'exception'  => $e::class,
                'message'    => $e->getMessage(),
                'request_id' => $trace->requestId,
            ]);

            return false;
        }
    }

    public function purge(DateTimeImmutable $olderThan, int $chunkSize = 5000): int
    {
        try {
            return $this->purgeOrFail($olderThan, $chunkSize);
        } catch (Throwable $e) {
            $this->logger->warning('Monitor purge başarısız', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function purgeOrFail(DateTimeImmutable $olderThan, int $chunkSize = 5000): int
    {
        $chunkSize = max(1, min($chunkSize, 50_000));
        $cutoff    = $olderThan->format('Y-m-d H:i:s');
        $total     = 0;

        $database = ($this->databaseProvider)();
        $pdo      = $database->getConnection();

        foreach ([self::TABLE_QUERY, self::TABLE_EXCEPTION, self::TABLE_REQUEST] as $table) {
            $total += $this->purgeTable($database, $pdo, $table, $cutoff, $chunkSize);
        }

        return $total;
    }

    public function driver(): string
    {
        return 'database';
    }

    private function qualify(Database $database, string $table): string
    {
        return $database->getDriver()->quoteIdentifier($database->getConfig()->prefix . $table);
    }

    private function insertRequest(Database $database, PDO $pdo, RequestTrace $trace): void
    {
        $sql = 'INSERT INTO ' . $this->qualify($database, self::TABLE_REQUEST) . ' ('
            . 'request_id, method, uri, route_name, status, duration_ms, memory_kb,'
            . 'query_count, query_time_ms, cache_hits, cache_misses, n_plus_one,'
            . 'ip, user_uuid, headers, request_body, response_body'
            . ') VALUES ('
            . ':request_id, :method, :uri, :route_name, :status, :duration_ms, :memory_kb,'
            . ':query_count, :query_time_ms, :cache_hits, :cache_misses, :n_plus_one,'
            . ':ip, :user_uuid, :headers, :request_body, :response_body'
            . ')';

        $pdo->prepare($sql)->execute([
            'request_id'    => $trace->requestId,
            'method'        => $trace->method,
            'uri'           => $trace->uri,
            'route_name'    => $trace->routeName,
            'status'        => $trace->status,
            'duration_ms'   => $trace->durationMs,
            'memory_kb'     => $trace->memoryKb,
            'query_count'   => $trace->queryCount,
            'query_time_ms' => round($trace->queryTimeMs, 2),
            'cache_hits'    => $trace->cacheHits,
            'cache_misses'  => $trace->cacheMisses,
            'n_plus_one'    => $trace->nPlusOne ? 1 : 0,
            'ip'            => $trace->ip,
            'user_uuid'     => $trace->userUuid,
            'headers'       => $this->encode($trace->headers),
            'request_body'  => $trace->requestBody,
            'response_body' => $trace->responseBody,
        ]);
    }

    private function insertQueries(Database $database, PDO $pdo, RequestTrace $trace): void
    {
        if ($trace->queries === []) {
            return;
        }

        $rows   = [];
        $params = [];

        foreach ($trace->queries as $i => $query) {
            $rows[] = "(:rid{$i}, :sql{$i}, :bind{$i}, :dur{$i})";

            $params["rid{$i}"]  = $trace->requestId;
            $params["sql{$i}"]  = $query['sql'];
            $params["bind{$i}"] = $this->encode($query['bindings']);
            $params["dur{$i}"]  = round($query['duration_ms'], 2);
        }

        $sql = 'INSERT INTO ' . $this->qualify($database, self::TABLE_QUERY)
            . ' (request_id, sql_text, bindings, duration_ms) VALUES '
            . implode(', ', $rows);

        $pdo->prepare($sql)->execute($params);
    }

    private function insertExceptions(Database $database, PDO $pdo, RequestTrace $trace): void
    {
        if ($trace->exceptions === []) {
            return;
        }

        $rows   = [];
        $params = [];

        foreach ($trace->exceptions as $i => $exception) {
            $rows[] = "(:rid{$i}, :cls{$i}, :msg{$i}, :file{$i}, :line{$i}, :trace{$i})";

            $params["rid{$i}"]   = $trace->requestId;
            $params["cls{$i}"]   = $exception['class'];
            $params["msg{$i}"]   = $exception['message'];
            $params["file{$i}"]  = $exception['file'];
            $params["line{$i}"]  = $exception['line'];
            $params["trace{$i}"] = $this->encode($exception['trace']);
        }

        $sql = 'INSERT INTO ' . $this->qualify($database, self::TABLE_EXCEPTION)
            . ' (request_id, exception_class, message, file, line, trace) VALUES '
            . implode(', ', $rows);

        $pdo->prepare($sql)->execute($params);
    }

    private function purgeTable(
        Database $database,
        PDO $pdo,
        string $table,
        string $cutoff,
        int $chunkSize
    ): int {
        $qualified = $this->qualify($database, $table);
        $driver    = $database->getDriver();

        $sql = match ($driver) {
            DatabaseDriver::MYSQL => 'DELETE FROM ' . $qualified
                . ' WHERE created_at < :cutoff LIMIT ' . $chunkSize,

            DatabaseDriver::SQLSERVER => 'DELETE TOP (' . $chunkSize . ') FROM ' . $qualified
                . ' WHERE created_at < :cutoff',

            default => 'DELETE FROM ' . $qualified . ' WHERE id IN ('
                . 'SELECT id FROM ' . $qualified
                . ' WHERE created_at < :cutoff ORDER BY id LIMIT ' . $chunkSize . ')',
        };

        $statement = $pdo->prepare($sql);
        $deleted   = 0;

        do {
            $statement->execute(['cutoff' => $cutoff]);
            $affected = $statement->rowCount();
            $deleted += $affected;
        } while ($affected === $chunkSize);

        return $deleted;
    }

    /**
     * @param array<mixed> $value
     */
    private function encode(array $value): ?string
    {
        if ($value === []) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    private function rollbackQuietly(?PDO $pdo): void
    {
        if ($pdo === null) {
            return;
        }

        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
        }
    }
}
