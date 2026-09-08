<?php

declare(strict_types=1);

namespace System\Console\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Option
{
    /**
     * @param string $name Option adı (örn: "force")
     * @param string|null $shortcut Kısayol (örn: "f" -> -f)
     * @param string $description Açıklama
     * @param bool $requiresValue Değer gerektirir mi?
     * @param mixed $default Varsayılan değer
     */
    public function __construct(
        public string $name,
        public ?string $shortcut = null,
        public string $description = '',
        public bool $requiresValue = false,
        public mixed $default = null,
    ) {}
}
