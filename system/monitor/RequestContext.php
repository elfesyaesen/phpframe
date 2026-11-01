<?php

declare(strict_types=1);

namespace System\Monitor;

final class RequestContext
{
    public function __construct(
        private readonly float $startedAt,
        private readonly string $rawInput,
        private readonly bool $bufferingResponse,
    ) {}

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    public function rawInput(): string
    {
        return $this->rawInput;
    }

    public function responseBody(): string
    {
        if (!$this->bufferingResponse) {
            return '';
        }

        $contents = ob_get_contents();

        return $contents === false ? '' : $contents;
    }
}
