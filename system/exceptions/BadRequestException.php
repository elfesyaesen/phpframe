<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class BadRequestException extends HttpException
{
    public function __construct(
        string $message = 'Geçersiz istek',
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, Status::BAD_REQUEST->value, $headers, $context, $previous);
    }
}
