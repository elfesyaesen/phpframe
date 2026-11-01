<?php

declare(strict_types=1);

namespace System\Container\Lifetime;

enum Lifetime: string
{
    case TRANSIENT = 'transient';

    case SINGLETON = 'singleton';

    case SCOPED = 'scoped';

    case INSTANCE = 'instance';

    public function rank(): int
    {
        return match ($this) {
            self::TRANSIENT => 0,
            self::SCOPED    => 1,
            self::SINGLETON => 2,
            self::INSTANCE  => 3,
        };
    }

    public function isShared(): bool
    {
        return $this !== self::TRANSIENT;
    }

    public function requiresScope(): bool
    {
        return $this === self::SCOPED;
    }

    public function label(): string
    {
        return $this->value;
    }
}
