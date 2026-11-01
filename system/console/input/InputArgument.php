<?php

declare(strict_types=1);

namespace System\Console\Input;

class InputArgument
{
    public const REQUIRED = 1;
    public const OPTIONAL = 2;
    public const IS_ARRAY = 4;

    public function __construct(
        public string $name,
        public int $mode = self::OPTIONAL,
        public string $description = '',
        public mixed $default = null,
    ) {
        if ($this->isRequired() && $this->default !== null) {
            throw new \InvalidArgumentException(
                'Zorunlu argümanlar varsayılan değer alamaz.'
            );
        }

        if ($this->isArray() && $this->default !== null && !is_array($this->default)) {
            throw new \InvalidArgumentException(
                'Array argümanlar için varsayılan değer array olmalıdır.'
            );
        }
    }

    public function isRequired(): bool
    {
        return (bool) ($this->mode & self::REQUIRED);
    }

    public function isOptional(): bool
    {
        return !$this->isRequired();
    }

    public function isArray(): bool
    {
        return (bool) ($this->mode & self::IS_ARRAY);
    }

    public function getDefault(): mixed
    {
        return $this->isArray() ? ($this->default ?? []) : $this->default;
    }
}
