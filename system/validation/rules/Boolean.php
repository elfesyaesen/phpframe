<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class Boolean implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true);
    }

    public function message(): string
    {
        return ':field dogru veya yanlis olmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.boolean';
    }
}
