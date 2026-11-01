<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class ServiceUnavailableException extends HttpException
{
    public function __construct(
        string $message = 'Hizmet geçici olarak kullanılamıyor',
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, Status::SERVICE_UNAVAILABLE->value, $headers, $context, $previous);
    }
}
