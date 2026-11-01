<?php

declare(strict_types=1);

namespace System\Container\Contract;

interface DisposableInterface
{
    public function dispose(): void;
}
