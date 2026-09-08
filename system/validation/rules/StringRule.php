<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;

class StringRule implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return is_string($value);
    }

    public function message(): string
    {
        return ':field metin tipinde olmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.string';
    }
}
