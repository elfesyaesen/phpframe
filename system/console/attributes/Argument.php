<?php

declare(strict_types=1);

namespace System\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Argument
{
    /**
     * @param string $name
     * @param string $description
     * @param bool $required
     * @param mixed $default
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public bool $required = false,
        public mixed $default = null,
    ) {}
}
