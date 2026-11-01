<?php

declare(strict_types=1);

namespace System\Container\Core;

use System\Container\Context\ContextualBinding;
use System\Container\Contract\ContainerBuilderInterface;

final class ContextualNeedsBuilder
{
    public function __construct(
        private readonly ContainerBuilderInterface $builder,
        private readonly string $consumer,
        private readonly string $needs,
        private readonly ?string $source = null,
    ) {}

    /**
     * @param class-string|string $id
     */
    public function give(string $id): ContainerBuilderInterface
    {
        $this->builder->addContextualBinding(new ContextualBinding(
            consumer: $this->consumer,
            needs: $this->needs,
            giveId: $id,
            source: $this->source,
        ));

        return $this->builder;
    }

    public function giveValue(mixed $value): ContainerBuilderInterface
    {
        $this->builder->addContextualBinding(new ContextualBinding(
            consumer: $this->consumer,
            needs: $this->needs,
            giveValue: $value,
            isValue: true,
            source: $this->source,
        ));

        return $this->builder;
    }
}
