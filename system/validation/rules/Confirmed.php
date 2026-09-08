<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\DataAwareRuleInterface;
use System\Validation\DataAccessor;

final class Confirmed implements DataAwareRuleInterface
{
    private array $data = [];
    private string $field = '';

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function setField(string $field): self
    {
        $this->field = $field;
        return $this;
    }

    public function passes(mixed $value): bool
    {
        return $value === (new DataAccessor())->get($this->data, $this->field . '_confirmation');
    }

    public function message(): string
    {
        return ':field tekrari eslesmiyor';
    }
    public function messageKey(): string
    {
        return 'validation.confirmed';
    }
}
