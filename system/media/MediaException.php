<?php

declare(strict_types=1);

namespace System\Media;

use RuntimeException;
use Throwable;

final class MediaException extends RuntimeException
{
    public const UNKNOWN_COLLECTION = 'unknown_collection';
    public const NOT_UPLOADABLE     = 'not_uploadable';
    public const INVALID_FILE       = 'invalid_file';
    public const TOO_LARGE          = 'too_large';
    public const TOO_MANY_PIXELS    = 'too_many_pixels';
    public const QUOTA_EXCEEDED     = 'quota_exceeded';
    public const SCAN_REJECTED      = 'scan_rejected';
    public const DRIVER_UNAVAILABLE = 'driver_unavailable';
    public const STORAGE_FAILED     = 'storage_failed';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public function isClientError(): bool
    {
        return !in_array($this->reason, [self::DRIVER_UNAVAILABLE, self::STORAGE_FAILED], true);
    }
}
