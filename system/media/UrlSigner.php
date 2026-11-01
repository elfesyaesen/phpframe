<?php

declare(strict_types=1);

namespace System\Media;

use SensitiveParameter;

final class UrlSigner
{
    private const CLOCK_SKEW = 60;

    public function __construct(
        #[SensitiveParameter] private readonly string $key,
        #[SensitiveParameter] private readonly string $previousKey,
        private readonly int $ttl,
    ) {}

    public function expiry(?int $now = null): int
    {
        $now ??= time();

        return (intdiv($now, $this->ttl) + 2) * $this->ttl;
    }

    public function sign(string $uuid, string $file, int $expires): string
    {
        return self::base64Url(hash_hmac('sha256', $this->message($uuid, $file, $expires), $this->key, true));
    }

    public function verify(string $uuid, string $file, int $expires, string $signature, ?int $now = null): bool
    {
        $now ??= time();

        if ($this->key === '' || $signature === '' || $expires < $now) {
            return false;
        }

        if ($expires > $now + 2 * $this->ttl + self::CLOCK_SKEW) {
            return false;
        }

        $message = $this->message($uuid, $file, $expires);

        foreach ([$this->key, $this->previousKey] as $key) {
            if ($key === '') {
                continue;
            }

            $expected = self::base64Url(hash_hmac('sha256', $message, $key, true));

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function message(string $uuid, string $file, int $expires): string
    {
        return 'v1|' . strtolower($uuid) . '|' . $file . '|' . $expires;
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
