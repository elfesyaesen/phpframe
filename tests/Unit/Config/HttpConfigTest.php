<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Config\HttpConfig;

#[CoversClass(HttpConfig::class)]
final class HttpConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function origins(): iterable
    {
        yield 'tam origin'          => ['https://app.example.com', 'https://app.example.com'];
        yield 'sonda eğik çizgi'    => ['https://app.example.com/', 'https://app.example.com'];
        yield 'büyük harf'          => ['HTTPS://App.Example.com', 'https://app.example.com'];
        yield 'özel port korunur'   => ['http://localhost:3000', 'http://localhost:3000'];
        yield 'varsayılan port'     => ['https://app.example.com:443', 'https://app.example.com'];
        yield 'şemasız'             => ['phpframe.local', null];
        yield 'yol içeriyor'        => ['https://app.example.com/app', null];
        yield 'sorgu içeriyor'      => ['https://app.example.com?x=1', null];
        yield 'http dışı şema'      => ['ftp://app.example.com', null];
        yield 'kimlik bilgisi'      => ['https://user:pw@app.example.com', null];
        yield 'boş'                 => ['', null];
    }

    #[DataProvider('origins')]
    public function testNormalizeOrigin(string $input, ?string $expected): void
    {
        self::assertSame($expected, HttpConfig::normalizeOrigin($input));
    }

    public function testInvalidOriginsReportsOnlyUnparseableEntries(): void
    {
        $config = new HttpConfig(
            trustedProxies: [],
            corsOrigins: ['*', 'https://app.example.com', 'phpframe.local', 'https://x.com/app'],
        );

        self::assertSame(['phpframe.local', 'https://x.com/app'], $config->invalidOrigins());
    }
}
