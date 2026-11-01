<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use System\Config\MediaCollection;
use System\Media\Image\ImageProcessor;

#[CoversClass(MediaCollection::class)]
#[CoversClass(ImageProcessor::class)]
final class MediaConfigTest extends TestCase
{
    public function testValidCollectionIsParsed(): void
    {
        $collection = MediaCollection::fromArray('product', [
            'visibility' => 'public',
            'mimes'      => ['image/jpeg', 'image/png'],
            'max_mb'     => 3,
            'variants'   => ['thumb' => 320],
            'permission' => 'media.upload',
        ]);

        self::assertTrue($collection->isPublic());
        self::assertTrue($collection->allowsImages());
        self::assertSame(3 * 1024 * 1024, $collection->maxBytes);
        self::assertSame(['thumb' => 320], $collection->variants);
        self::assertSame('media.upload', $collection->permission);
        self::assertTrue($collection->uploadable);
    }

    public function testDefaultsArePrivate(): void
    {
        $collection = MediaCollection::fromArray('doc', ['mimes' => ['application/pdf']]);

        self::assertFalse($collection->isPublic());
        self::assertNull($collection->permission);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenMimeProvider(): array
    {
        return [
            'svg'        => ['image/svg+xml'],
            'html'       => ['text/html'],
            'xhtml'      => ['application/xhtml+xml'],
            'javascript' => ['application/javascript'],
        ];
    }

    #[DataProvider('forbiddenMimeProvider')]
    public function testScriptCapableMimesAreRejected(string $mime): void
    {
        $this->expectException(InvalidArgumentException::class);

        MediaCollection::fromArray('bad', ['mimes' => ['image/png', $mime]]);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function invalidDefinitionProvider(): array
    {
        return [
            'büyük harfli ad'      => ['Product', ['mimes' => ['image/png']]],
            'yol karakteri'        => ['a/b', ['mimes' => ['image/png']]],
            'tür yok'              => ['empty', ['mimes' => []]],
            'geçersiz görünürlük'  => ['x', ['mimes' => ['image/png'], 'visibility' => 'world']],
            'ayrılmış varyant adı' => ['x', ['mimes' => ['image/png'], 'variants' => ['file' => 100]]],
            'varyant adında nokta' => ['x', ['mimes' => ['image/png'], 'variants' => ['a.b' => 100]]],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    #[DataProvider('invalidDefinitionProvider')]
    public function testInvalidDefinitionsAreRejected(string $name, array $definition): void
    {
        $this->expectException(InvalidArgumentException::class);

        MediaCollection::fromArray($name, $definition);
    }

    /**
     * @return array<string, array{int, int, int, array{0: int, 1: int}}>
     */
    public static function fitProvider(): array
    {
        return [
            'yatay küçültme'     => [4000, 3000, 800, [800, 600]],
            'dikey küçültme'     => [3000, 4000, 800, [600, 800]],
            'zaten küçük'        => [300, 200, 800, [300, 200]],
            'tam sınırda'        => [800, 400, 800, [800, 400]],
            'çok ince en az 1px' => [10000, 1, 320, [320, 1]],
        ];
    }

    /**
     * @param array{0: int, 1: int} $expected
     */
    #[DataProvider('fitProvider')]
    public function testFitNeverUpscalesAndKeepsRatio(int $w, int $h, int $edge, array $expected): void
    {
        self::assertSame($expected, ImageProcessor::fit($w, $h, $edge));
    }
}
