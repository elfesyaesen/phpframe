<?php

declare(strict_types=1);

namespace System\Container\Core;

use System\Container\Context\ContextualBinding;
use System\Container\Contract\ContainerBuilderInterface;

final class ContextualBindingBuilder
{
    public function __construct(
        private readonly ContainerBuilderInterface $builder,
        private readonly string $consumer,
        private readonly ?string $source = null,
    ) {}

    /**
     * @param string $abstract
     */
    public function needs(string $abstract): ContextualNeedsBuilder
    {
        return new ContextualNeedsBuilder(
            $this->builder,
            $this->consumer,
            $abstract,
            $this->source,
        );
    }
}
