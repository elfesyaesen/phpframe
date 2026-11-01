<?php

declare(strict_types=1);

namespace System\Validation\Rules;

final class UniqueActive extends AbstractUniqueRule
{
    protected function extraWhere(\PDO $pdo): string
    {
        return ' AND ' . $this->quoteId($pdo, 'deleted_at') . ' IS NULL';
    }
}
