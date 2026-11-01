<?php

declare(strict_types=1);

namespace System\Container\Contract;

interface BootableProviderInterface
{
    public function boot(ContainerInterface $container): void;
}
