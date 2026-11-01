<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class MethodNotAllowedException extends HttpException
{
    public function __construct(
        string $message = 'Metod izin verilmiyor',
        array $allowedMethods = [],
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        if (!empty($allowedMethods)) {
            $headers['Allow'] = implode(', ', $allowedMethods);
            $context['allowed_methods'] = $allowedMethods;
        }

        parent::__construct($message, Status::METHOD_NOT_ALLOWED->value, $headers, $context, $previous);
    }
}
