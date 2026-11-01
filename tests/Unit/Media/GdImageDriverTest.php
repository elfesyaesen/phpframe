<?php

declare(strict_types=1);

namespace Tests\Unit\Media;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use System\Config\MediaConfig;
use System\Media\Image\GdImageDriver;
use System\Media\Image\ImageProcessor;
use System\Media\MediaException;

#[CoversClass(GdImageDriver::class)]
#[CoversClass(ImageProcessor::class)]
final class GdImageDriverTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        if (!GdImageDriver::available()) {
            self::markTestSkipped('GD (WebP desteğiyle) yüklü değil — php.ini: extension=gd');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testOutputIsReencodedWebpWithoutMetadataOrTrailingPayload(): void
    {
        $jpeg = $this->jpeg(64, 48);
        $jpeg = substr($jpeg, 0, 2) . "\xFF\xFE\x00\x16GPS-SECRET-COMMENT!!" . substr($jpeg, 2);
        $path = $this->write($jpeg . '<script>alert(1)</script><?php echo 1; ?>');

        $result = (new GdImageDriver())->process($path, 'image/jpeg', ['full' => 2560, 'thumb' => 32], ['webp'], 80);

        $full = $result['variants']['full']['files']['webp'];
        self::assertStringStartsWith('RIFF', $full);
        self::assertSame('WEBP', substr($full, 8, 4));
        self::assertStringNotContainsString('GPS-SECRET-COMMENT', $full);
        self::assertStringNotContainsString('<script>', $full);
        self::assertStringNotContainsString('<?php', $full);

        self::assertSame([64, 48], [$result['variants']['full']['w'], $result['variants']['full']['h']]);
        self::assertSame([32, 24], [$result['variants']['thumb']['w'], $result['variants']['thumb']['h']]);
    }

    public function testNonImageContentIsRejected(): void
    {
        $path = $this->write('<html><body>not an image</body></html>');

        $this->expectException(MediaException::class);

        (new GdImageDriver())->process($path, 'image/png', ['full' => 100], ['webp'], 80);
    }

    public function testPixelLimitIsEnforcedBeforeDecoding(): void
    {
        $path = $this->write($this->png(200, 200));

        try {
            $this->processor(maxPixels: 10_000)->process($path, 'image/png', []);
            self::fail('Piksel sınırını aşan resim kabul edildi.');
        } catch (MediaException $e) {
            self::assertSame(MediaException::TOO_MANY_PIXELS, $e->reason);
        }
    }

    public function testProcessorAddsFullVariant(): void
    {
        $path   = $this->write($this->png(300, 100));
        $result = $this->processor()->process($path, 'image/png', ['thumb' => 150]);

        self::assertArrayHasKey('full', $result['variants']);
        self::assertSame(150, $result['variants']['thumb']['w']);
        self::assertSame(50, $result['variants']['thumb']['h']);
    }

    private function processor(int $maxPixels = 40_000_000): ImageProcessor
    {
        return new ImageProcessor(new MediaConfig(
            signingKey: str_repeat('k', 32),
            previousSigningKey: '',
            publicBaseUrl: 'http://localhost/media',
            privateBaseUrl: 'http://localhost/media',
            signedTtl: 600,
            sendfile: MediaConfig::SENDFILE_NONE,
            nginxInternalPrefix: '/_protected_media',
            imageDriver: 'gd',
            maxPixels: $maxPixels,
            maxDimension: 2560,
            quality: 80,
            avif: false,
            userQuotaMb: 0,
            crossOriginResourcePolicy: 'cross-origin',
            storagePath: sys_get_temp_dir(),
            collections: [],
        ));
    }

    private function jpeg(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    private function png(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function write(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pf_img_');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
