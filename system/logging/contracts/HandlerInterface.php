<?php

declare(strict_types=1);

namespace System\Logging\Contracts;

use System\Logging\LogLevel;
use System\Logging\LogRecord;

interface HandlerInterface
{
    public function handle(LogRecord $record): void;

    public function isHandling(LogLevel $level): bool;
}
