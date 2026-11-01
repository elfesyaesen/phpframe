<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class Required implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === '', $value === [] => false,
            is_string($value) => trim($value) !== '',
            default => true,
        };
    }

    public function message(): string
    {
        return ':field alani zorunludur';
    }
    public function messageKey(): string
    {
        return 'validation.required';
    }
}
