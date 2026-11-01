<?php

declare(strict_types=1);

namespace App\Api\Exceptions;

use RuntimeException;
use Throwable;

final class RecordInUseException extends RuntimeException
{
    public function __construct(
        public readonly string $identifier,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Kayıt kullanımda, silinemez: %s', $identifier), 0, $previous);
    }
}
