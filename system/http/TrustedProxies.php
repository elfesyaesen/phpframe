<?php

declare(strict_types=1);

namespace System\Http;

final class TrustedProxies
{
    /** @var list<array{0: string, 1: int}> */
    private array $ranges = [];

    /**
     * @param list<string> $entries
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            $range = self::parse($entry);

            if ($range !== null) {
                $this->ranges[] = $range;
            }
        }
    }

    public function contains(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        foreach ($this->ranges as [$network, $prefix]) {
            if (strlen($network) === strlen($binary) && self::prefixMatches($binary, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    public static function invalidEntries(array $entries): array
    {
        return array_values(array_filter($entries, static fn(string $e): bool => self::parse($e) === null));
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function parse(string $entry): ?array
    {
        $entry = trim($entry);
        [$address, $prefix] = str_contains($entry, '/') ? explode('/', $entry, 2) : [$entry, null];

        $binary = @inet_pton($address);

        if ($binary === false) {
            return null;
        }

        $bits = strlen($binary) * 8;

        if ($prefix === null) {
            return [$binary, $bits];
        }

        if (!ctype_digit($prefix) || (int) $prefix > $bits) {
            return null;
        }

        return [$binary, (int) $prefix];
    }

    private static function prefixMatches(string $ip, string $network, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);

        if (strncmp($ip, $network, $fullBytes) !== 0) {
            return false;
        }

        $remainingBits = $prefix % 8;

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ip[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
