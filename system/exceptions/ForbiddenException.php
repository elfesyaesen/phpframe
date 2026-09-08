<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class ForbiddenException extends HttpException
{
    public function __construct(
        string $message = 'Erişim reddedildi',
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, Status::FORBIDDEN->value, $headers, $context, $previous);
    }
}
