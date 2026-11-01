<?php

declare(strict_types=1);

namespace System\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Command
{
    /**
     * @param string $name
     * @param string $description
     * @param string|null $usage
     * @param array<string> $aliases
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public ?string $usage = null,
        public array $aliases = [],
    ) {}
}
