<?php

declare(strict_types=1);

namespace System\Logging\Contracts;

use System\Logging\LogRecord;

interface FormatterInterface
{
    public function format(LogRecord $record): string;
}
