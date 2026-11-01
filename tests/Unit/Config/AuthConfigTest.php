<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Config\AppConfig;
use System\Config\AuthConfig;

#[CoversClass(AuthConfig::class)]
final class AuthConfigTest extends TestCase
{
    private function app(string $url = 'http://localhost/app/'): AppConfig
    {
        return new AppConfig(
            root: '/tmp',
            url: $url,
            production: false,
            timezone: 'UTC',
        );
    }

    private function auth(string $resetUrl): AuthConfig
    {
        return new AuthConfig(
            secretKey: str_repeat('x', 32),
            accessTokenExpire: 3600,
            refreshTokenExpire: 604800,
            passwordResetUrl: $resetUrl,
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function linkProvider(): array
    {
        return [
            'göreli yol' => [
                'reset-password',
                'http://localhost/app/reset-password?token=T',
            ],
            'göreli, baştaki slash' => [
                '/reset-password',
                'http://localhost/app/reset-password?token=T',
            ],
            'göreli, mevcut query → & ile eklenir' => [
                'reset?lang=tr',
                'http://localhost/app/reset?lang=tr&token=T',
            ],

            'mutlak https, ayrı origin' => [
                'https://app.example.com/reset',
                'https://app.example.com/reset?token=T',
            ],
            'mutlak custom scheme (deep link)' => [
                'myapp://reset',
                'myapp://reset?token=T',
            ],
            'mutlak + mevcut query' => [
                'myapp://reset?src=mail',
                'myapp://reset?src=mail&token=T',
            ],
        ];
    }

    #[DataProvider('linkProvider')]
    public function testResetLinkBuildsExpectedUrl(string $configured, string $expected): void
    {
        self::assertSame(
            $expected,
            $this->auth($configured)->resetLink($this->app(), 'T')
        );
    }

    public function testAbsoluteUrlIsNotPrefixedWithAppUrl(): void
    {
        $link = $this->auth('myapp://reset')->resetLink($this->app(), 'T');

        self::assertStringStartsWith('myapp://', $link);
        self::assertStringNotContainsString('localhost', $link);
    }

    public function testTokenIsAlwaysAppendedAsQueryParameter(): void
    {
        $token = bin2hex(random_bytes(32));
        $link  = $this->auth('reset-password')->resetLink($this->app(), $token);

        self::assertStringContainsString('token=' . $token, $link);
    }

    public function testPasswordResetDefaultsAreConservative(): void
    {
        $auth = $this->auth('reset-password');

        self::assertSame(1800, $auth->passwordResetExpire);
        self::assertLessThan(
            $auth->accessTokenExpire,
            $auth->passwordResetExpire,
            'Sıfırlama token ömrü access token ömründen kısa olmalı.'
        );

        self::assertSame(5, $auth->passwordResetMaxAttempts);
        self::assertGreaterThan(0, $auth->passwordResetMaxAttempts);
    }

    public function testHasStrongSecretRequiresAtLeast32Characters(): void
    {
        self::assertFalse($this->secretOf(str_repeat('x', 31))->hasStrongSecret());
        self::assertTrue($this->secretOf(str_repeat('x', 32))->hasStrongSecret());
        self::assertFalse($this->secretOf('')->hasStrongSecret());
    }

    private function secretOf(string $secret): AuthConfig
    {
        return new AuthConfig(
            secretKey: $secret,
            accessTokenExpire: 3600,
            refreshTokenExpire: 604800,
        );
    }
}
