<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class TooManyRequestsException extends HttpException
{
    public function __construct(
        string $message = 'Çok fazla istek',
        ?int $retryAfter = null,
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
            $context['retry_after'] = $retryAfter;
        }

        parent::__construct($message, Status::TOO_MANY_REQUESTS->value, $headers, $context, $previous);
    }
}
