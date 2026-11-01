<?php

declare(strict_types=1);

namespace System\Validation\Contracts;

interface ParameterizedRuleInterface extends RuleInterface
{
    public function setParameters(array $params): self;
}
