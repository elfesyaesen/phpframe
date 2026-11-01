<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class Numeric implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return is_numeric($value);
    }

    public function message(): string
    {
        return ':field sayisal bir deger olmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.numeric';
    }
}
