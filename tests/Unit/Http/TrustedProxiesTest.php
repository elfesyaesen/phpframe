<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Http\TrustedProxies;

#[CoversClass(TrustedProxies::class)]
final class TrustedProxiesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'ipv4 cidr içi'         => ['10.200.3.4', true];
        yield 'ipv4 cidr dışı'        => ['11.0.0.1', false];
        yield 'ipv4 tek adres'        => ['192.168.1.5', true];
        yield 'ipv4 komşu adres'      => ['192.168.1.6', false];
        yield 'ipv6 cidr içi'         => ['fd12:3456::1', true];
        yield 'ipv6 kanonik olmayan'  => ['fd00:0:0::5', true];
        yield 'ipv6 cidr dışı'        => ['fe80::1', false];
        yield 'ipv6 loopback /128'    => ['::1', true];
        yield 'v4-mapped ayrı aile'   => ['::ffff:10.0.0.1', false];
        yield 'ip değil'              => ['not-an-ip', false];
    }

    #[DataProvider('addresses')]
    public function testContains(string $ip, bool $expected): void
    {
        $proxies = new TrustedProxies([
            '10.0.0.0/8', 'fd00::/8', '::1/128', '192.168.1.5',
            'localhost', '10.0.0.0/33', 'fd00::/129',
        ]);

        self::assertSame($expected, $proxies->contains($ip));
    }

    public function testInvalidEntries(): void
    {
        self::assertSame(
            ['localhost', '10.0.0.0/33', 'fd00::/129', '1.2.3.4/x'],
            TrustedProxies::invalidEntries(['10.0.0.0/8', 'localhost', '10.0.0.0/33', 'fd00::/129', '1.2.3.4/x', '::1']),
        );
    }

    public function testZeroPrefixMatchesEverythingInFamily(): void
    {
        self::assertTrue((new TrustedProxies(['0.0.0.0/0']))->contains('8.8.8.8'));
        self::assertFalse((new TrustedProxies(['0.0.0.0/0']))->contains('::1'));
    }
}
