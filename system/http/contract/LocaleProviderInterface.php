<?php

declare(strict_types=1);

namespace System\Http\Contract;

interface LocaleProviderInterface
{
    public function locale(): string;
}
