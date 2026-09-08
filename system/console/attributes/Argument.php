<?php

declare(strict_types=1);

namespace System\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Argument
{
    /**
     * @param string $name Argüman adı
     * @param string $description Açıklama
     * @param bool $required Zorunlu mu?
     * @param mixed $default Varsayılan değer
     */
    public function __construct(
        public string $name,
        public string $description = '',
        public bool $required = false,
        public mixed $default = null,
    ) {}
}
