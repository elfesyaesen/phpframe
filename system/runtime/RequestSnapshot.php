<?php

declare(strict_types=1);

namespace System\Runtime;

final readonly class RequestSnapshot
{
    public function __construct(
        public float $startedAt,
        public string $rawInput,
        public bool $bufferingResponse,
    ) {}

    /**
     * @param array<string, mixed> $server
     */
    public static function capture(array $server, bool $bufferingResponse, bool $readInput = true): self
    {
        return new self(
            startedAt: isset($server['REQUEST_TIME_FLOAT'])
                ? (float) $server['REQUEST_TIME_FLOAT']
                : microtime(true),
            rawInput: $readInput ? self::readRawInput() : '',
            bufferingResponse: $bufferingResponse,
        );
    }

    public static function forCli(): self
    {
        return new self(microtime(true), '', false);
    }

    private static function readRawInput(): string
    {
        $input = @file_get_contents('php://input');

        return $input === false ? '' : $input;
    }
}
