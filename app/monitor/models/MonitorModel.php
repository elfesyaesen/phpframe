<?php

declare(strict_types=1);

namespace App\Monitor\Models;

use System\Config\MonitorConfig;
use System\Cache\CacheInterface;
use System\Database\Database;
use System\Database\DatabaseDriver;
use System\Engine\BaseModel;
use System\Monitor\Storage\DatabaseStorage;

final class MonitorModel extends BaseModel
{
    private readonly DatabaseDriver $driver;

    public function __construct(
        Database $database,
        CacheInterface $cache,
        private readonly MonitorConfig $monitor,
    ) {
        parent::__construct($database, $cache);

        $this->driver = $database->getDriver();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = 'SELECT id, request_id, method, uri, route_name, status,'
            . ' duration_ms, memory_kb, query_count, query_time_ms,'
            . ' cache_hits, cache_misses, n_plus_one, ip, user_uuid, created_at'
            . ' FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
            . ' WHERE ' . $where
            . ' ORDER BY id DESC'

            . ' LIMIT ' . max(1, min($limit, 200))
            . ' OFFSET ' . max(0, $offset);

        return $this->selectAll($sql, $params);
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $row = $this->selectOne(
            'SELECT COUNT(*) AS total FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE ' . $where,
            $params
        );

        return (int) ($row['total'] ?? 0);
    }

    public function latestId(): int
    {
        $row = $this->selectOne(
            'SELECT MAX(id) AS last_id FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
        );

        return (int) ($row['last_id'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $requestId): ?array
    {
        return $this->selectOne(
            'SELECT * FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE request_id = :rid LIMIT 1',
            ['rid' => $requestId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function queriesFor(string $requestId): array
    {
        return $this->selectAll(
            'SELECT sql_text, bindings, duration_ms FROM ' . $this->table(DatabaseStorage::TABLE_QUERY)
                . ' WHERE request_id = :rid ORDER BY id ASC',
            ['rid' => $requestId]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exceptionsFor(string $requestId): array
    {
        return $this->selectAll(
            'SELECT exception_class, message, file, line, trace FROM '
                . $this->table(DatabaseStorage::TABLE_EXCEPTION)
                . ' WHERE request_id = :rid ORDER BY id ASC',
            ['rid' => $requestId]
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{total: int, errors: int, slow: int, n_plus_one: int, avg_ms: float, max_ms: float, s2: int, s3: int, s4: int, s5: int}
     */
    public function summary(array $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $slowMs = $this->monitor->slowMs;

        $row = $this->selectOne(
            'SELECT COUNT(*) AS total,'
                . ' SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) AS errors,'
                . ' SUM(CASE WHEN duration_ms >= :slow THEN 1 ELSE 0 END) AS slow,'
                . ' SUM(CASE WHEN n_plus_one = 1 THEN 1 ELSE 0 END) AS n_plus_one,'
                . ' SUM(CASE WHEN status < 300 THEN 1 ELSE 0 END) AS s2,'
                . ' SUM(CASE WHEN status >= 300 AND status < 400 THEN 1 ELSE 0 END) AS s3,'
                . ' SUM(CASE WHEN status >= 400 AND status < 500 THEN 1 ELSE 0 END) AS s4,'
                . ' AVG(duration_ms) AS avg_ms, MAX(duration_ms) AS max_ms'
                . ' FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE ' . $where,
            $params + ['slow' => $slowMs]
        );

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'errors'     => (int) ($row['errors'] ?? 0),
            'slow'       => (int) ($row['slow'] ?? 0),
            'n_plus_one' => (int) ($row['n_plus_one'] ?? 0),
            'avg_ms'     => round((float) ($row['avg_ms'] ?? 0), 2),
            'max_ms'     => round((float) ($row['max_ms'] ?? 0), 2),
            's2'         => (int) ($row['s2'] ?? 0),
            's3'         => (int) ($row['s3'] ?? 0),
            's4'         => (int) ($row['s4'] ?? 0),
            's5'         => (int) ($row['errors'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array{start: int, ok: int, client: int, server: int}>
     */
    public function timeline(array $filters, int $buckets = 30): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $hours  = $this->hours($filters);
        $end    = time();
        $size   = max(1, intdiv($hours * 3600, $buckets));
        $start  = $end - ($size * $buckets);

        $ladder = [];
        for ($i = 0; $i < $buckets; $i++) {
            $ladder[] = 'WHEN created_at < :e' . $i . ' THEN ' . $i;
            $params['e' . $i] = date('Y-m-d H:i:s', $start + (($i + 1) * $size));
        }

        $rows = $this->selectAll(
            'SELECT bucket, cls, COUNT(*) AS hits FROM ('
                . 'SELECT CASE ' . implode(' ', $ladder) . ' ELSE ' . ($buckets - 1) . ' END AS bucket,'
                . ' CASE WHEN status >= 500 THEN 5 WHEN status >= 400 THEN 4 ELSE 2 END AS cls'
                . ' FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE ' . $where
                . ') buckets GROUP BY bucket, cls',
            $params
        );

        $series = [];
        for ($i = 0; $i < $buckets; $i++) {
            $series[$i] = ['start' => $start + ($i * $size), 'ok' => 0, 'client' => 0, 'server' => 0];
        }

        foreach ($rows as $row) {
            $key = match ((int) $row['cls']) {
                5       => 'server',
                4       => 'client',
                default => 'ok',
            };
            $series[(int) $row['bucket']][$key] += (int) $row['hits'];
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function slowestEndpoints(array $filters, int $limit = 6): array
    {
        [$where, $params] = $this->buildWhere($filters);

        return $this->selectAll(
            'SELECT method, uri, COUNT(*) AS hits, AVG(duration_ms) AS avg_ms,'
                . ' MAX(duration_ms) AS max_ms'
                . ' FROM ' . $this->table(DatabaseStorage::TABLE_REQUEST)
                . ' WHERE ' . $where
                . ' GROUP BY method, uri'
                . ' ORDER BY avg_ms DESC'
                . ' LIMIT ' . max(1, min($limit, 50)),
            $params
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $hours  = $this->hours($filters);
        $clauses = ['created_at >= :since'];
        $params  = ['since' => date('Y-m-d H:i:s', time() - ($hours * 3600))];

        if (!empty($filters['method'])) {
            $clauses[]        = 'method = :method';
            $params['method'] = strtoupper((string) $filters['method']);
        }

        if (!empty($filters['status_class'])) {
            $class                  = (int) $filters['status_class'];
            $clauses[]              = 'status >= :status_min AND status < :status_max';
            $params['status_min']   = $class * 100;
            $params['status_max']   = ($class + 1) * 100;
        }

        if (!empty($filters['q'])) {
            $clauses[]   = 'uri LIKE :q';
            $params['q'] = '%' . (string) $filters['q'] . '%';
        }

        if (!empty($filters['min_duration'])) {
            $clauses[]              = 'duration_ms >= :min_duration';
            $params['min_duration'] = (float) $filters['min_duration'];
        }

        if (!empty($filters['n_plus_one'])) {
            $clauses[] = 'n_plus_one = 1';
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function hours(array $filters): int
    {
        return max(1, min((int) ($filters['hours'] ?? 24), 24 * 90));
    }

    private function table(string $name): string
    {
        return $this->driver->quoteIdentifier($this->prefix() . $name);
    }
}
