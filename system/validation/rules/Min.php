<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

final class Min implements ParameterizedRuleInterface
{
    private int|float $min = 0;

    public function setParameters(array $params): self
    {
        $this->min = is_numeric($params[0] ?? 0) ? $params[0] + 0 : 0;
        return $this;
    }

    public function passes(mixed $value): bool
    {
        return match (true) {
            is_string($value)  => mb_strlen($value) >= $this->min,
            is_numeric($value) => $value >= $this->min,
            is_array($value)   => count($value) >= $this->min,
            default            => false,
        };
    }

    public function message(): string
    {
        return ":field en az {$this->min} olmalidir";
    }
    public function messageKey(): string
    {
        return 'validation.min';
    }

    public function messageParams(): array
    {
        return ['min' => $this->min];
    }
}
