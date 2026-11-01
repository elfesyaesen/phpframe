<?php

declare(strict_types=1);

namespace System\Monitor\Scrubber;

use System\Monitor\Contracts\ScrubberInterface;

final class KeyScrubber implements ScrubberInterface
{
    public const DEFAULT_KEYS = [
        'password', 'passwd', 'secret', 'token', 'authorization',
        'auth', 'api_key', 'apikey', 'credential', 'cookie',
        'pwd', 'pass', 'refresh_token', 'access_token', 'client_secret',
        'private_key', 'signature', 'otp', 'pin', 'cvv', 'cvc',
        'card', 'pan', 'iban', 'session',

        '_hash',
    ];

    public const EXACT_STRING_KEYS = ['code', 'hash'];

    public const DEFAULT_MASK = '***FILTERED***';

    /**
     * @param array<int, string> $sensitiveKeys
     */
    public function __construct(
        private readonly array $sensitiveKeys = self::DEFAULT_KEYS,
        private readonly string $mask = self::DEFAULT_MASK,
    ) {}

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function scrub(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitive((string) $key)
                || (is_string($value) && in_array(strtolower((string) $key), self::EXACT_STRING_KEYS, true))
            ) {
                $result[$key] = is_string($value)
                    ? $this->maskPreservingScheme($value)
                    : $this->mask;

                continue;
            }

            $result[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $result;
    }

    public function scrubText(string $body, int $maxBytes): string
    {
        if ($body === '') {
            return '';
        }

        $structured = $this->decodeStructured($body);

        if ($structured !== null) {
            $encoded = json_encode(
                $this->scrub($structured),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if ($encoded !== false) {
                return $this->truncate($encoded, $maxBytes);
            }
        }

        return $this->truncate($body, $maxBytes);
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeStructured(string $body): ?array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (!str_contains($body, '=') || str_contains($body, "\n")) {
            return null;
        }

        parse_str($body, $parsed);

        return $parsed === [] ? null : $parsed;
    }

    private function isSensitive(string $key): bool
    {
        $lower = strtolower($key);

        foreach ($this->sensitiveKeys as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskPreservingScheme(string $value): string
    {
        foreach (['Bearer', 'Basic', 'Digest'] as $scheme) {
            if (stripos($value, $scheme . ' ') === 0) {
                return $scheme . ' ' . $this->mask;
            }
        }

        return $this->mask;
    }

    private function truncate(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes) . "\n…[truncated]";
    }
}
