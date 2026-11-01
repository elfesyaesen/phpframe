<?php

declare(strict_types=1);

namespace System\Monitor\Storage;

use DateTimeImmutable;
use System\Monitor\Contracts\StorageInterface;
use System\Monitor\RequestTrace;

final class NullStorage implements StorageInterface
{
    public function store(RequestTrace $trace): bool
    {
        return false;
    }

    public function purge(DateTimeImmutable $olderThan, int $chunkSize = 5000): int
    {
        return 0;
    }

    public function driver(): string
    {
        return 'null';
    }
}
