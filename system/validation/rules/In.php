<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

final class In implements ParameterizedRuleInterface
{
    private array $allowed = [];

    public function setParameters(array $params): self
    {
        $this->allowed = $params;
        return $this;
    }

    public function passes(mixed $value): bool
    {
        // Skaler olmayan girdi (array/object) listede olamaz; (string) cast'i de
        // "Array" üretip yanıltır. Güvenli taraf: reddet.
        if (!is_scalar($value)) {
            return false;
        }

        return in_array($value, $this->allowed, true) || in_array((string) $value, $this->allowed, true);
    }

    public function message(): string
    {
        return ':field su degerlerden biri olmalidir: ' . implode(', ', $this->allowed);
    }
    public function messageKey(): string
    {
        return 'validation.in';
    }

    public function messageParams(): array
    {
        return ['values' => implode(', ', $this->allowed)];
    }
}
