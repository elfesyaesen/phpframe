<?php

declare(strict_types=1);

namespace App\Api\Exceptions;

use RuntimeException;
use Throwable;

final class DuplicateNameException extends RuntimeException
{
    public function __construct(
        public readonly string $name,
        public readonly string $field = 'name',
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('%s zaten kullanımda: %s', $field, $name), 0, $previous);
    }
}
