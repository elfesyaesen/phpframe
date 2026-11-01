<?php

declare(strict_types=1);

namespace System\Validation\Contracts;

interface DataAwareRuleInterface extends RuleInterface
{
    public function setData(array $data): self;

    public function setField(string $field): self;
}
