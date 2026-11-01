<?php

declare(strict_types=1);

namespace System\Container\Contract;

use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    public function createScope(?string $name = null): ScopeInterface;

    public function scope(): ?ScopeInterface;

    public function isCompiled(): bool;

    public function hash(): ?string;
}
