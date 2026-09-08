<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

final class NotIn implements ParameterizedRuleInterface
{
    private array $disallowed = [];

    public function setParameters(array $params): self
    {
        $this->disallowed = $params;
        return $this;
    }

    public function passes(mixed $value): bool
    {
        // Skaler olmayan girdi geçersiz sayılır (reddedilir): (string) cast'i "Array"
        // üretip blacklist'i sessizce bypass etmesini önler.
        if (!is_scalar($value)) {
            return false;
        }

        return !in_array($value, $this->disallowed, true) && !in_array((string) $value, $this->disallowed, true);
    }

    public function message(): string
    {
        return ':field su degerlerden biri olmamalidir: ' . implode(', ', $this->disallowed);
    }
    public function messageKey(): string
    {
        return 'validation.not_in';
    }

    public function messageParams(): array
    {
        return ['values' => implode(', ', $this->disallowed)];
    }
}
