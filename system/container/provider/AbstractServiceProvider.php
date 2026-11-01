<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ServiceProviderInterface;
use System\Runtime\Sapi;

abstract class AbstractServiceProvider implements ServiceProviderInterface
{
    public function provides(): array
    {
        return [];
    }

    public function supports(Sapi $sapi): bool
    {
        return true;
    }
}
