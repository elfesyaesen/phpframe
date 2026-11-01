<?php

declare(strict_types=1);

namespace System\Logging\ErrorRenderer;

use System\Exceptions\HttpException;

interface ErrorRendererInterface
{
    public function render(HttpException $exception, bool $debug = false): string;

    public function getContentType(): string;
}
