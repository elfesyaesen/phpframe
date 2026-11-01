<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class Nullable implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return true;
    }

    public function message(): string
    {
        return '';
    }
}
