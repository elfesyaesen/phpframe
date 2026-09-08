<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

final class Between implements ParameterizedRuleInterface
{
    private int|float $min = 0;
    private int|float $max = 0;

    public function setParameters(array $params): self
    {
        $this->min = is_numeric($params[0] ?? 0) ? $params[0] + 0 : 0;
        $this->max = is_numeric($params[1] ?? 0) ? $params[1] + 0 : 0;
        return $this;
    }

    public function passes(mixed $value): bool
    {
        return match (true) {
            is_string($value)  => ($len = mb_strlen($value)) >= $this->min && $len <= $this->max,
            is_numeric($value) => $value >= $this->min && $value <= $this->max,
            is_array($value)   => ($cnt = count($value)) >= $this->min && $cnt <= $this->max,
            default            => false,
        };
    }

    public function message(): string
    {
        return ":field {$this->min} ile {$this->max} arasinda olmalidir";
    }
    public function messageKey(): string
    {
        return 'validation.between';
    }

    public function messageParams(): array
    {
        return ['min' => $this->min, 'max' => $this->max];
    }
}
