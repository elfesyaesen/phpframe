<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

final class Regex implements ParameterizedRuleInterface
{
    private string $pattern = '';

    public function setParameters(array $params): self
    {
        $this->pattern = $params[0] ?? '';
        return $this;
    }

    public function passes(mixed $value): bool
    {
        return (is_string($value) || is_numeric($value)) && preg_match($this->pattern, (string) $value) === 1;
    }

    public function message(): string
    {
        return ':field formati gecersiz';
    }
    public function messageKey(): string
    {
        return 'validation.regex';
    }
}
