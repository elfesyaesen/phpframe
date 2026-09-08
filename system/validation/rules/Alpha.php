<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class Alpha implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[\pL\pM]+$/u', $value) === 1;
    }

    public function message(): string
    {
        return ':field sadece harflerden olusmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.alpha';
    }
}
