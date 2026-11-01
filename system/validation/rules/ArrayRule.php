<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class ArrayRule implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return is_array($value);
    }

    public function message(): string
    {
        return ':field dizi tipinde olmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.array';
    }
}
