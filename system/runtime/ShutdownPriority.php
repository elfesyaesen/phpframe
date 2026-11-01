<?php

declare(strict_types=1);

namespace System\Runtime;

final class ShutdownPriority
{
    public const MONITOR_FLUSH = 100;

    public const FATAL_RESPONSE = 50;

    public const SCOPE_DISPOSE = 0;

    private function __construct()
    {
    }
}
