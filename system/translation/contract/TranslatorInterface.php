<?php

declare(strict_types=1);

namespace System\Translation\Contract;

interface TranslatorInterface
{
    /**
     * @param array<string, string|int|float> $params
     */
    public function trans(string $key, array $params = []): string;
}
