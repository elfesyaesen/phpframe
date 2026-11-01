<?php

declare(strict_types=1);

namespace System\Monitor\Sampler;

use System\Monitor\Contracts\SamplerInterface;
use System\Monitor\RequestTrace;

final class PathSampler implements SamplerInterface
{
    private const SCAN_PATTERNS = [
        '/.git', '/.env', '/.aws', '/wp-', '/wordpress',
        '/phpmyadmin', '/adminer', '/actuator', '/vendor/phpunit',
        '/.well-known/traffic-advice', '/cgi-bin',
    ];

    /**
     * @param array<int, string> $skipPrefixes
     */
    public function __construct(
        private readonly array $skipPrefixes = [],
    ) {}

    public function shouldStore(RequestTrace $trace): bool
    {
        foreach ($this->skipPrefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($trace->uri, $prefix)) {
                return false;
            }
        }

        $lowerUri = strtolower($trace->uri);
        foreach (self::SCAN_PATTERNS as $pattern) {
            if (str_contains($lowerUri, $pattern)) {
                return false;
            }
        }

        return true;
    }
}
