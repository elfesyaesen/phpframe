<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

final class Max implements ParameterizedRuleInterface
{
    private int|float $max = 0;

    public function setParameters(array $params): self
    {
        $this->max = is_numeric($params[0] ?? 0) ? $params[0] + 0 : 0;
        return $this;
    }

    public function passes(mixed $value): bool
    {
        return match (true) {
            is_string($value)  => mb_strlen($value) <= $this->max,
            is_numeric($value) => $value <= $this->max,
            is_array($value)   => count($value) <= $this->max,
            default            => false,
        };
    }

    public function message(): string
    {
        return ":field en fazla {$this->max} olmalidir";
    }
    public function messageKey(): string
    {
        return 'validation.max';
    }

    public function messageParams(): array
    {
        return ['max' => $this->max];
    }
}
