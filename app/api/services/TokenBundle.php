<?php

declare(strict_types=1);

namespace App\Api\Services;

final readonly class TokenBundle
{
    /**
     * @param array<string, mixed> $user
     */
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public string $expiredAt,
        public array $user,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token'  => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expired_at'    => $this->expiredAt,
            'user'          => $this->user,
        ];
    }
}
