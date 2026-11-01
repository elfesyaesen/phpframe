<?php

declare(strict_types=1);

namespace System\Validation\Rules;

final class Unique extends AbstractUniqueRule
{
    public static function make(string $table, string $column, ?int $ignoreId = null): self
    {
        $instance = new self();
        $instance->setParameters(
            $ignoreId !== null ? [$table, $column, (string) $ignoreId] : [$table, $column]
        );
        return $instance;
    }
}
