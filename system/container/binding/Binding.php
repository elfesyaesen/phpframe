<?php

declare(strict_types=1);

namespace System\Container\Binding;

final readonly class Binding
{
    /**
     * @param class-string $id
     * @param class-string $target
     * @param string|null $source
     */
    public function __construct(
        public string $id,
        public string $target,
        public ?string $source = null,
    ) {}
}
