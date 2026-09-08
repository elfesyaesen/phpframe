<?php

declare(strict_types=1);

namespace System\Logging\ErrorRenderer;

use System\Exceptions\HttpException;

final class JsonRenderer implements ErrorRendererInterface
{
    public function render(HttpException $exception, bool $debug = false): string
    {
        return json_encode(
            $exception->toArray($debug),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );
    }

    public function getContentType(): string
    {
        return 'application/json; charset=utf-8';
    }
}
