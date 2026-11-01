<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use DateTimeImmutable;
use System\Monitor\RequestTrace;

interface StorageInterface
{
    public function store(RequestTrace $trace): bool;

    public function purge(DateTimeImmutable $olderThan, int $chunkSize = 5000): int;

    public function driver(): string;
}
