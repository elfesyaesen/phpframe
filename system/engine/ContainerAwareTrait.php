<?php

declare(strict_types=1);

namespace System\Engine;

use Psr\Container\ContainerInterface;

/**
 * ContainerAwareInterface için standart uygulama.
 */
trait ContainerAwareTrait
{
    protected ?ContainerInterface $container = null;

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }
}
