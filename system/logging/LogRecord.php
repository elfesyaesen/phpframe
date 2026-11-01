<?php

declare(strict_types=1);

namespace System\Logging;

use DateTimeImmutable;

final class LogRecord
{
    public readonly DateTimeImmutable $datetime;

    public function __construct(
        public readonly LogLevel $level,
        public readonly string $message,
        public readonly array $context = []
    ) {
        $this->datetime = new DateTimeImmutable();
    }
}
