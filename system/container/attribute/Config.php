<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Config
{
    /**
     * @param string $key
     */
    public function __construct(
        public string $key,
    ) {}
}
