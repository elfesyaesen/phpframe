<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

interface ScrubberInterface
{
    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function scrub(array $data): array;

    public function scrubText(string $body, int $maxBytes): string;
}
