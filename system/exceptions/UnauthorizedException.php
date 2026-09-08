<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class UnauthorizedException extends HttpException
{
    public function __construct(
        string $message = 'Yetkilendirme gerekli',
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        $headers['WWW-Authenticate'] ??= 'Bearer';
        parent::__construct($message, Status::UNAUTHORIZED->value, $headers, $context, $previous);
    }
}
