<?php

declare(strict_types=1);

namespace System\Logging;

enum LogLevel: string
{
    case EMERGENCY = 'emergency';
    case ALERT     = 'alert';
    case CRITICAL  = 'critical';
    case ERROR     = 'error';
    case WARNING   = 'warning';
    case NOTICE    = 'notice';
    case INFO      = 'info';
    case DEBUG     = 'debug';

    public function priority(): int
    {
        return match ($this) {
            self::EMERGENCY => 0,
            self::ALERT     => 1,
            self::CRITICAL  => 2,
            self::ERROR     => 3,
            self::WARNING   => 4,
            self::NOTICE    => 5,
            self::INFO      => 6,
            self::DEBUG     => 7,
        };
    }

    public function includes(self $level): bool
    {
        return $level->priority() <= $this->priority();
    }
}
