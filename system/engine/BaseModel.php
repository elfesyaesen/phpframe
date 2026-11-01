<?php

declare(strict_types=1);

namespace System\Engine;

use PDO;
use PDOException;
use System\Cache\CacheInterface;
use System\Database\Database;

abstract class BaseModel
{
    public function __construct(
        private readonly Database $database,
        protected readonly CacheInterface $cache,
    ) {
    }

    protected function prefix(): string
    {
        return $this->database->getConfig()->prefix;
    }

    protected function pdo(): PDO
    {
        return $this->database->getConnection();
    }

    protected function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        return $this->cache->remember($key, $ttl, $callback);
    }

    protected function forget(string $key): bool
    {
        return $this->cache->delete($key);
    }

    protected function cacheKey(string ...$parts): string
    {
        return implode(':', $parts);
    }

    protected function isUniqueViolation(PDOException $e): bool
    {
        $sqlState   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23505' || ($sqlState === '23000' && $driverCode === 1062);
    }

    protected function isForeignKeyViolation(PDOException $e): bool
    {
        $sqlState   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23503'
            || ($sqlState === '23000' && in_array($driverCode, [1451, 1452], true));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function selectAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    protected function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    protected function callFunction(string $function, array $params = []): array
    {
        $placeholders = implode(
            ', ',
            array_map(static fn(string $k): string => ':' . $k, array_keys($params))
        );

        return $this->selectAll(
            'SELECT * FROM ' . $function . '(' . $placeholders . ')',
            $params
        );
    }
}
