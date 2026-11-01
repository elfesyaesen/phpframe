<?php

declare(strict_types=1);

namespace System\Console\Commands\Concerns;

trait GeneratesClassNames
{
    private const CLASS_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    private function className(string $name, string $suffix): ?string
    {
        $name = trim($name);

        if (preg_match(self::CLASS_NAME_PATTERN, $name) !== 1) {
            return null;
        }

        $name = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));

        return str_ends_with($name, $suffix) ? $name : $name . $suffix;
    }

    private function invalidClassName(string $name): void
    {
        $this->error("Geçersiz ad: '{$name}'");
        $this->writeln('Ad harfle başlamalı; yalnızca harf, rakam, alt çizgi ve tire içerebilir.');
    }
}
