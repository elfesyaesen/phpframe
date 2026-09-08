<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class ConflictException extends HttpException
{
    public function __construct(
        string $message = 'Kaynak çakışması',
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, Status::CONFLICT->value, $headers, $context, $previous);
    }
}
