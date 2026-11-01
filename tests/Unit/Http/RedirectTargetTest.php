<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Http\Response;

#[CoversClass(Response::class)]
final class RedirectTargetTest extends TestCase
{
    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function targetProvider(): array
    {
        return [
            'kök'                         => ['/', false, true],
            'göreli yol'                  => ['/monitor', false, true],
            'göreli yol + sorgu'          => ['/monitor?tab=1', false, true],
            'protocol-relative'           => ['//evil.com', false, false],
            'ters eğik çizgi'             => ['/\\evil.com', false, false],
            'tab ile gizlenmiş //'        => ["/\t/evil.com", false, false],
            'CRLF'                        => ["/a\r\nSet-Cookie: x=1", false, false],
            'boş'                         => ['', false, false],
            'mutlak URL izinsiz'          => ['https://example.com/', false, false],
            'mutlak URL izinli'           => ['https://example.com/a', true, true],
            'javascript: izinli olsa da'  => ['javascript://example.com/%0Aalert(1)', true, false],
            'şemasız host'                => ['evil.com', true, false],
            'izinli + protocol-relative'  => ['//evil.com', true, false],
        ];
    }

    #[DataProvider('targetProvider')]
    public function testIsSafeRedirectTarget(string $url, bool $allowExternal, bool $expected): void
    {
        self::assertSame($expected, Response::isSafeRedirectTarget($url, $allowExternal));
    }
}
