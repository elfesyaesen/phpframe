<?php

declare(strict_types=1);

namespace System\Container\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Named
{
    public function __construct(
        public string $name,
    ) {}

    public static function idFor(string $type, string $name): string
    {
        return $type . '@' . $name;
    }
}
