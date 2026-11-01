<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class AlphaNum implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return (is_string($value) || is_numeric($value)) && preg_match('/^[\pL\pM\pN]+$/u', (string) $value) === 1;
    }

    public function message(): string
    {
        return ':field sadece harf ve rakamlardan olusmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.alpha_num';
    }
}
