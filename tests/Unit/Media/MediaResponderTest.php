<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Media\MediaResponder;

#[CoversClass(MediaResponder::class)]
final class MediaResponderTest extends TestCase
{
    /**
     * @return array<string, array{string, int, array{0: int, 1: int}|null|false}>
     */
    public static function rangeProvider(): array
    {
        return [
            'başlık yok'              => ['', 100, null],
            'tam aralık'              => ['bytes=0-9', 100, [0, 9]],
            'açık uçlu'               => ['bytes=90-', 100, [90, 99]],
            'son N bayt'              => ['bytes=-10', 100, [90, 99]],
            'son N > boyut'           => ['bytes=-500', 100, [0, 99]],
            'bitiş dosyayı aşıyor'    => ['bytes=50-500', 100, [50, 99]],
            'başlangıç dosya dışında' => ['bytes=100-', 100, false],
            'ters aralık'             => ['bytes=20-10', 100, false],
            'son 0 bayt'              => ['bytes=-0', 100, false],
            'çoklu aralık yok sayılır' => ['bytes=0-1,5-6', 100, null],
            'bozuk birim'             => ['items=0-1', 100, null],
            'bozuk sözdizimi'         => ['bytes=abc', 100, null],
            'boş dosya'               => ['bytes=0-1', 0, null],
        ];
    }

    /**
     * @param array{0: int, 1: int}|null|false $expected
     */
    #[DataProvider('rangeProvider')]
    public function testParseRange(string $header, int $size, array|null|false $expected): void
    {
        self::assertSame($expected, MediaResponder::parseRange($header, $size));
    }
}
