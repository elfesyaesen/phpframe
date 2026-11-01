<?php

declare(strict_types=1);

namespace System\Console\Output;

enum Verbosity: int
{
    case QUIET = 16;
    case NORMAL = 32;
    case VERBOSE = 64;
    case VERY_VERBOSE = 128;
    case DEBUG = 256;

    public function isQuiet(): bool
    {
        return $this === self::QUIET;
    }

    public function isVerbose(): bool
    {
        return $this->value >= self::VERBOSE->value;
    }

    public function isVeryVerbose(): bool
    {
        return $this->value >= self::VERY_VERBOSE->value;
    }

    public function isDebug(): bool
    {
        return $this === self::DEBUG;
    }

    public static function fromOptions(array $options): self
    {
        if (isset($options['quiet']) || isset($options['q'])) {
            return self::QUIET;
        }

        $verboseCount = 0;

        if (isset($options['v'])) {
            $verboseCount = is_bool($options['v']) ? 1 : strlen((string) $options['v']);
        }

        if (isset($options['verbose'])) {
            $verboseCount = max($verboseCount, 1);
        }

        if (isset($options['vv'])) {
            $verboseCount = max($verboseCount, 2);
        }

        if (isset($options['vvv'])) {
            $verboseCount = max($verboseCount, 3);
        }

        return match ($verboseCount) {
            1 => self::VERBOSE,
            2 => self::VERY_VERBOSE,
            3 => self::DEBUG,
            default => self::NORMAL,
        };
    }
}
