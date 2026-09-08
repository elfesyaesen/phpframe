<?php

declare(strict_types=1);

namespace System\Logging\Handlers;

use System\Logging\Contracts\HandlerInterface;
use System\Logging\LogLevel;
use System\Logging\LogRecord;

class NullHandler implements HandlerInterface
{
    public function handle(LogRecord $record): void
    {
        // Test icin bos handler
    }

    public function isHandling(LogLevel $level): bool
    {
        return true;
    }
}
