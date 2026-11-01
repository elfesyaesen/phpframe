<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Tagged
{
    public function __construct(
        public string $tag,
    ) {}
}
