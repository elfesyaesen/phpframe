<?php

declare(strict_types=1);

namespace System\Console\Input;

class InputOption
{
    public const VALUE_NONE = 1;
    public const VALUE_REQUIRED = 2;
    public const VALUE_OPTIONAL = 4;
    public const VALUE_IS_ARRAY = 8;
    public const VALUE_NEGATABLE = 16;

    public function __construct(
        public string $name,
        public ?string $shortcut = null,
        public int $mode = self::VALUE_NONE,
        public string $description = '',
        public mixed $default = null,
    ) {
        if ($this->isArray() && !$this->acceptValue()) {
            throw new \InvalidArgumentException(
                'VALUE_IS_ARRAY modu VALUE_REQUIRED veya VALUE_OPTIONAL ile kullanılmalıdır.'
            );
        }

        if ($this->isNegatable() && $this->acceptValue()) {
            throw new \InvalidArgumentException(
                'VALUE_NEGATABLE modu değer kabul eden modlarla kullanılamaz.'
            );
        }

        if ($this->shortcut !== null && strlen($this->shortcut) !== 1) {
            throw new \InvalidArgumentException(
                'Shortcut tek karakter olmalıdır.'
            );
        }
    }

    public function acceptValue(): bool
    {
        return $this->isValueRequired() || $this->isValueOptional();
    }

    public function isValueRequired(): bool
    {
        return (bool) ($this->mode & self::VALUE_REQUIRED);
    }

    public function isValueOptional(): bool
    {
        return (bool) ($this->mode & self::VALUE_OPTIONAL);
    }

    public function isArray(): bool
    {
        return (bool) ($this->mode & self::VALUE_IS_ARRAY);
    }

    public function isNegatable(): bool
    {
        return (bool) ($this->mode & self::VALUE_NEGATABLE);
    }

    public function isFlag(): bool
    {
        return (bool) ($this->mode & self::VALUE_NONE);
    }

    public function getDefault(): mixed
    {
        if ($this->isFlag()) {
            return false;
        }

        if ($this->isArray()) {
            return $this->default ?? [];
        }

        return $this->default;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getShortcut(): ?string
    {
        return $this->shortcut;
    }
}
