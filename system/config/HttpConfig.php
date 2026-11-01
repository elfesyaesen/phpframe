<?php

declare(strict_types=1);

namespace System\Config;

final readonly class HttpConfig
{
    /**
     * @param list<string> $trustedProxies
     * @param list<string> $corsOrigins
     */
    public function __construct(
        public array $trustedProxies,
        public array $corsOrigins,
    ) {}

    public static function fromEnv(EnvReader $env): self
    {
        return new self(
            trustedProxies: $env->list('TRUSTED_PROXIES', '127.0.0.1,::1'),
            corsOrigins: array_map(
                static fn(string $o): string => $o === '*' ? $o : (self::normalizeOrigin($o) ?? $o),
                $env->list('CORS_ORIGINS'),
            ),
        );
    }

    public static function normalizeOrigin(string $value): ?string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);

        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return null;
        }

        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        $defaultPort = strtolower($parts['scheme']) === 'https' ? 443 : 80;

        if (isset($parts['port']) && $parts['port'] !== $defaultPort) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    /**
     * @return list<string>
     */
    public function invalidOrigins(): array
    {
        return array_values(array_filter(
            $this->corsOrigins,
            static fn(string $o): bool => $o !== '*' && self::normalizeOrigin($o) !== $o,
        ));
    }
}
