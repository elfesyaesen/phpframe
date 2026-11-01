<?php

declare(strict_types=1);

namespace System\Http;

final class SecurityHeaders
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');

        header('X-XSS-Protection: 0');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: interest-cohort=()');

        if (self::isHttps($_SERVER)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function isHttps(array $server): bool
    {
        return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (int) ($server['SERVER_PORT'] ?? 80) === 443
            || ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
