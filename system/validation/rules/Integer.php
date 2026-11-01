<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class Integer implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    public function message(): string
    {
        return ':field tam sayi olmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.integer';
    }
}
