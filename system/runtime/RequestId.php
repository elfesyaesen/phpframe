<?php

declare(strict_types=1);

namespace System\Runtime;

use System\Http\TrustedProxies;

final readonly class RequestId
{
    private function __construct(
        public string $value,
        public bool $fromUpstream,
    ) {}

    /**
     * @param array<string, mixed> $server
     */
    public static function fromServer(array $server, TrustedProxies $trustedProxies): self
    {
        $incoming = $server['HTTP_X_REQUEST_ID'] ?? '';
        $remote = $server['REMOTE_ADDR'] ?? '';

        if (is_string($incoming)
            && is_string($remote)
            && $trustedProxies->contains($remote)
            && preg_match('/^[A-Za-z0-9\-]{1,64}$/', $incoming) === 1
        ) {
            return new self($incoming, true);
        }

        return self::generate();
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)), false);
    }

    public static function forCli(): self
    {
        return new self('cli-' . bin2hex(random_bytes(8)), false);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
