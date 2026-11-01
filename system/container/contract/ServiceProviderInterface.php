<?php

declare(strict_types=1);

namespace System\Container\Contract;

use System\Runtime\Sapi;

interface ServiceProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void;

    /**
     * @return list<class-string>
     */
    public function provides(): array;

    public function supports(Sapi $sapi): bool;
}
