<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Inject
{
    /**
     * @param class-string $id
     */
    public function __construct(
        public string $id,
    ) {}
}
