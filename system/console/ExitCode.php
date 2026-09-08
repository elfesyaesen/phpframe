<?php

declare(strict_types=1);

namespace System\Console;

enum ExitCode: int
{
    case SUCCESS = 0;
    case FAILURE = 1;
    case INVALID = 2;

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }
}
