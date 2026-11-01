<?php

declare(strict_types=1);

namespace System\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Option
{
    /**
     * @param string $name
     * @param string|null $shortcut
     * @param string $description
     * @param bool $requiresValue
     * @param mixed $default
     */
    public function __construct(
        public string $name,
        public ?string $shortcut = null,
        public string $description = '',
        public bool $requiresValue = false,
        public mixed $default = null,
    ) {}
}
