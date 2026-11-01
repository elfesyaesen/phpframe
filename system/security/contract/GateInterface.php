<?php

declare(strict_types=1);

namespace System\Security\Contract;

interface GateInterface
{
    public function allows(string $permission): bool;

    public function denies(string $permission): bool;

    public function authorize(string $permission): void;
}
