<?php

declare(strict_types=1);

namespace System\Container\Definition;

enum FactoryKind: string
{
    case STATIC_METHOD = 'static';

    case INVOKABLE = 'invokable';

    case COMPILABLE = 'compilable';

    case CLOSURE = 'closure';

    public function isCompilable(): bool
    {
        return $this !== self::CLOSURE;
    }
}
