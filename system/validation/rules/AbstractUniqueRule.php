<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Engine\ContainerAwareInterface;
use System\Engine\ContainerAwareTrait;
use System\Validation\Contracts\ParameterizedRuleInterface;

abstract class AbstractUniqueRule implements ParameterizedRuleInterface, ContainerAwareInterface
{
    use ContainerAwareTrait;

    protected string $table = '';
    protected string $column = '';
    protected ?int $ignoreId = null;
    protected string $idColumn = 'id';

    public function setParameters(array $params): self
    {
        $this->table = $this->sanitizeIdentifier($params[0] ?? '');
        $this->column = $this->sanitizeIdentifier($params[1] ?? '');
        $this->ignoreId = isset($params[2]) ? (int) $params[2] : null;
        $this->idColumn = $this->sanitizeIdentifier($params[3] ?? 'id');
        return $this;
    }

    public function passes(mixed $value): bool
    {
        if (!is_string($value) && !is_numeric($value)) {
            return false;
        }

        $pdo = $this->getPdo();
        $prefix = $this->tablePrefix();

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s = :value%s',
            $this->quoteId($pdo, $prefix . $this->table),
            $this->quoteId($pdo, $this->column),
            $this->extraWhere($pdo)
        );

        $params = ['value' => $value];

        if ($this->ignoreId !== null) {
            $sql .= sprintf(' AND %s != :ignore_id', $this->quoteId($pdo, $this->idColumn));
            $params['ignore_id'] = $this->ignoreId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }

    protected function extraWhere(\PDO $pdo): string
    {
        return '';
    }

    public function message(): string
    {
        return ':field zaten kullanımda';
    }

    public function messageKey(): string
    {
        return 'validation.unique';
    }

    protected function getPdo(): \PDO
    {
        return $this->database()->getConnection();
    }

    protected function tablePrefix(): string
    {
        return $this->database()->getConfig()->prefix;
    }

    private function database(): \System\Database\Database
    {
        if ($this->container === null) {
            throw new \RuntimeException(
                static::class . ' için Container enjekte edilmedi (RuleFactory üzerinden kullanın).'
            );
        }

        return $this->container->get(\System\Database\Database::class);
    }

    protected function sanitizeIdentifier(string $identifier): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
    }

    protected function quoteId(\PDO $pdo, string $identifier): string
    {
        return match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql'  => "`{$identifier}`",
            'sqlsrv' => "[{$identifier}]",
            default  => "\"{$identifier}\"",
        };
    }
}
