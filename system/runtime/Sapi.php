<?php

declare(strict_types=1);

namespace System\Runtime;

enum Sapi: string
{
    case Http = 'http';
    case Cli  = 'cli';

    public static function detect(): self
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ? self::Cli : self::Http;
    }

    public function isCli(): bool
    {
        return $this === self::Cli;
    }

    public function isHttp(): bool
    {
        return $this === self::Http;
    }
}
