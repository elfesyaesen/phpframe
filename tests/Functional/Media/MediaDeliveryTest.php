<?php

declare(strict_types=1);

namespace Tests\Functional\Media;

use CURLFile;
use Tests\Functional\FunctionalTestCase;

final class MediaDeliveryTest extends FunctionalTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $uploaded = [];

    private string $token = '';

    protected function tearDown(): void
    {
        foreach ($this->uploaded as $uuid) {
            $this->delete('/v1/api/media/' . $uuid, $this->token);
        }

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    public function testPrivateDocumentIsServedOnlyWithValidSignature(): void
    {
        $media = $this->uploadOk('document', self::PDF, 'fiyat listesi.pdf', 'application/pdf');

        self::assertSame('private', $media['visibility']);
        self::assertIsInt($media['expires_at']);
        $url = $this->relative((string) $media['urls']['file']);

        $ok = $this->fetch($url);
        self::assertSame(200, $ok['status']);
        self::assertSame(self::PDF, $ok['body']);
        self::assertSame('application/pdf', $ok['headers']['content-type'] ?? null);
        self::assertSame('nosniff', $ok['headers']['x-content-type-options'] ?? null);
        self::assertStringContainsString('sandbox', $ok['headers']['content-security-policy'] ?? '');
        self::assertStringStartsWith('attachment;', $ok['headers']['content-disposition'] ?? '');
        self::assertStringStartsWith('private', $ok['headers']['cache-control'] ?? '');

        self::assertSame(403, $this->fetch(strtok($url, '?'))['status'], 'İmzasız adres açıldı.');
        self::assertSame(403, $this->fetch($url . 'A')['status'], 'Değiştirilmiş imza kabul edildi.');
        self::assertSame(403, $this->fetch((string) preg_replace('/exp=(\d+)/', 'exp=${1}0', $url))['status'], 'Süre uzatılabildi.');
        self::assertSame(404, $this->fetch('/media/p/' . $media['uuid'] . '/file.pdf')['status'], 'Özel dosya public yoldan açıldı.');
    }

    public function testConditionalAndRangeRequests(): void
    {
        $media = $this->uploadOk('document', self::PDF, 'doc.pdf', 'application/pdf');
        $url   = $this->relative((string) $media['urls']['file']);

        $first = $this->fetch($url);
        $etag  = $first['headers']['etag'] ?? '';
        self::assertNotSame('', $etag);

        self::assertSame(304, $this->fetch($url, ['If-None-Match: ' . $etag])['status']);

        $partial = $this->fetch($url, ['Range: bytes=0-7']);
        self::assertSame(206, $partial['status']);
        self::assertSame('%PDF-1.4', $partial['body']);

        self::assertSame(416, $this->fetch($url, ['Range: bytes=99999-'])['status']);
    }

    public function testDeletedMediaIsNoLongerServed(): void
    {
        $media = $this->uploadOk('document', self::PDF, 'doc.pdf', 'application/pdf');
        $url   = $this->relative((string) $media['urls']['file']);

        self::assertSame(200, $this->delete('/v1/api/media/' . $media['uuid'], $this->token)['status']);
        $this->uploaded = array_values(array_diff($this->uploaded, [$media['uuid']]));

        self::assertSame(404, $this->fetch($url)['status']);
        self::assertSame(404, $this->get('/v1/api/media/' . $media['uuid'], $this->token)['status']);
    }

    public function testDangerousUploadsAreRejected(): void
    {
        $this->token = $this->accessToken();

        self::assertSame(422, $this->upload('document', '<?php echo 1;', 'x.pdf', 'application/pdf')['status']);
        self::assertSame(422, $this->upload('product', "GIF89a<script>alert(1)</script>", 'x.png', 'image/png')['status']);
        self::assertSame(422, $this->upload('yok_boyle', self::PDF, 'x.pdf', 'application/pdf')['status']);
        self::assertSame(422, $this->upload('avatar', self::PDF, 'x.pdf', 'application/pdf')['status']);
    }

    public function testUploadRequiresAuthentication(): void
    {
        self::assertSame(401, $this->upload('document', self::PDF, 'x.pdf', 'application/pdf', withToken: false)['status']);
    }

    public function testPublicImageIsReencodedAndImmutable(): void
    {
        $this->token = $this->accessToken();
        $response    = $this->upload('product', (string) base64_decode(self::PNG), 'urun.png', 'image/png');

        if ($response['status'] === 503) {
            self::markTestSkipped('Sunucuda resim sürücüsü (GD/Imagick) yok — php.ini: extension=gd');
        }

        self::assertSame(201, $response['status'], $response['raw']);
        $media = (array) $response['body'];
        $this->uploaded[] = (string) $media['uuid'];

        self::assertSame('public', $media['visibility']);
        self::assertSame('image/webp', $media['mime']);
        self::assertNull($media['expires_at']);
        self::assertArrayHasKey('thumb', $media['urls']);
        self::assertStringNotContainsString('sig=', (string) $media['urls']['full']);

        $image = $this->fetch($this->relative((string) $media['urls']['full']));
        self::assertSame(200, $image['status']);
        self::assertSame('image/webp', $image['headers']['content-type'] ?? null);
        self::assertStringContainsString('immutable', $image['headers']['cache-control'] ?? '');
        self::assertStringStartsWith('inline;', $image['headers']['content-disposition'] ?? '');
        self::assertSame('WEBP', substr($image['body'], 8, 4));
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadOk(string $collection, string $contents, string $name, string $mime): array
    {
        $this->token = $this->token !== '' ? $this->token : $this->accessToken();
        $response    = $this->upload($collection, $contents, $name, $mime);

        self::assertSame(201, $response['status'], $response['raw']);
        self::assertIsArray($response['body']);
        $this->uploaded[] = (string) $response['body']['uuid'];

        return $response['body'];
    }

    /**
     * @return array{status: int, body: array<string, mixed>|null, raw: string}
     */
    private function upload(string $collection, string $contents, string $name, string $mime, bool $withToken = true): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'pf_up_');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        $headers = ['Accept: application/json'];
        if ($withToken) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init(self::$baseUrl . '/v1/api/media');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => ['collection' => $collection, 'file' => new CURLFile($path, $mime, $name)],
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw    = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function fetch(string $path, array $headers = []): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response   = (string) curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $parsed = [];
        foreach (explode("\r\n", substr($response, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }

        return ['status' => $status, 'body' => substr($response, $headerSize), 'headers' => $parsed];
    }

    private function relative(string $url): string
    {
        $parts = parse_url($url);
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return ($parts['path'] ?? '/') . $query;
    }
}
