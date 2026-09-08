<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class Url implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    public function message(): string
    {
        return ':field gecerli bir URL olmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.url';
    }
}
