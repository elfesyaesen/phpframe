<?php

declare(strict_types=1);

namespace System\Container\Definition;

final readonly class LazyRef
{
    /**
     * @param class-string $id
     * @param \Closure():mixed $resolver
     */
    public function __construct(
        public string $id,
        private \Closure $resolver,
    ) {}

    public function __invoke(): mixed
    {
        return ($this->resolver)();
    }

    public function resolve(): mixed
    {
        return ($this->resolver)();
    }
}
