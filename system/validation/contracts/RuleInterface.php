<?php

declare(strict_types=1);

namespace System\Validation\Contracts;

interface RuleInterface
{
    public function passes(mixed $value): bool;

    public function message(): string;
}
