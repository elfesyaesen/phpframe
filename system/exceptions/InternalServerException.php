<?php

declare(strict_types=1);

namespace System\Exceptions;

use System\Helpers\Status;
use Throwable;

class InternalServerException extends HttpException
{
    public function __construct(
        string $message = 'Sunucu hatası',
        array $headers = [],
        array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, Status::INTERNAL_SERVER_ERROR->value, $headers, $context, $previous);
    }

    public static function wrap(Throwable $e): static
    {
        return new static(
            message: $e->getMessage(),
            context: [
                'original_exception' => $e::class,
            ],
            previous: $e
        );
    }
}
