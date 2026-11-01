<?php

declare(strict_types=1);

namespace System\Container\Compilation;

enum Severity: string
{
    case ERROR = 'error';

    case WARNING = 'warning';

    case NOTICE = 'notice';

    public function rank(): int
    {
        return match ($this) {
            self::ERROR   => 2,
            self::WARNING => 1,
            self::NOTICE  => 0,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ERROR   => 'HATA',
            self::WARNING => 'UYARI',
            self::NOTICE  => 'BİLGİ',
        };
    }
}
