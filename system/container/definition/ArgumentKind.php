<?php

declare(strict_types=1);

namespace System\Container\Definition;

enum ArgumentKind
{
    case SERVICE;

    case LITERAL;

    case EXTERNAL;

    case CONTAINER;

    case TAGGED;

    case DECORATED;
}
