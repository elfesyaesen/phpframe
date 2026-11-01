<?php

declare(strict_types=1);

namespace System\Media;

use System\Config\MediaConfig;

final class MediaResponder
{
    private const CHUNK = 65536;

    public function __construct(
        private readonly MediaConfig $config,
        private readonly MediaDisk $disk,
    ) {}

    /**
     * @param array<string, string> $server
     * @return bool
     */
    public function send(Media $media, string $variant, string $format, ?int $expires, array $server): bool
    {
        $entry = $media->file($variant, $format);
        if ($entry === null) {
            return false;
        }

        $path = $this->disk->localPath($entry['key']);
        if ($path === null) {
            return false;
        }

        $size = (int) (filesize($path) ?: 0);
        $etag = '"' . $entry['etag'] . '"';
        $mime = $entry['mime'];

        $this->commonHeaders($media, $mime, $variant, $format, $etag, $expires);

        if ($this->notModified($etag, (string) ($server['HTTP_IF_NONE_MATCH'] ?? ''))) {
            http_response_code(304);

            return true;
        }

        if ($this->config->sendfile !== MediaConfig::SENDFILE_NONE) {
            $this->delegate($entry['key'], $path);

            return true;
        }

        $isHead = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';
        $range  = self::parseRange((string) ($server['HTTP_RANGE'] ?? ''), $size);

        if ($range === false) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);

            return true;
        }

        [$start, $end] = $range ?? [0, $size - 1];
        $length = $size === 0 ? 0 : $end - $start + 1;

        if ($range !== null) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            http_response_code(200);
        }

        header('Content-Length: ' . $length);

        if (!$isHead && $length > 0) {
            $this->stream($path, $start, $length);
        }

        return true;
    }

    /**
     * @return array{0: int, 1: int}|null|false
     */
    public static function parseRange(string $header, int $size): array|null|false
    {
        if ($header === '' || !str_starts_with($header, 'bytes=') || $size === 0) {
            return null;
        }

        $spec = substr($header, 6);
        if (str_contains($spec, ',')) {
            return null;
        }

        if (preg_match('/^\s*(\d*)\s*-\s*(\d*)\s*$/', $spec, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }

        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix === 0) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $m[1];
        $end   = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);

        if ($start >= $size || $start > $end) {
            return false;
        }

        return [$start, $end];
    }

    private function commonHeaders(Media $media, string $mime, string $variant, string $format, string $etag, ?int $expires): void
    {
        $inline = str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cross-Origin-Resource-Policy: ' . $this->config->crossOriginResourcePolicy);
        header('Referrer-Policy: no-referrer');
        header('Accept-Ranges: bytes');
        header('ETag: ' . $etag);

        $timestamp = strtotime($media->createdAt);
        if ($timestamp !== false) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $timestamp) . ' GMT');
        }

        header('Content-Disposition: ' . self::disposition($inline, $media->originalName, $variant, $format));

        if ($media->isPublic()) {
            header('Cache-Control: public, max-age=31536000, immutable');
        } else {
            $remaining = max(0, ($expires ?? time()) - time());
            header('Cache-Control: private, max-age=' . $remaining);
        }

        header_remove('Pragma');
        header_remove('Expires');
    }

    private static function disposition(bool $inline, ?string $originalName, string $variant, string $format): string
    {
        $base = $originalName !== null ? pathinfo($originalName, PATHINFO_FILENAME) : '';
        if ($base === '') {
            $base = $variant;
        }

        $name  = $base . '.' . $format;
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $name);

        return ($inline ? 'inline' : 'attachment')
            . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
    }

    private function notModified(string $etag, string $ifNoneMatch): bool
    {
        if ($ifNoneMatch === '') {
            return false;
        }

        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }

    private function delegate(string $key, string $path): void
    {
        http_response_code(200);

        if ($this->config->sendfile === MediaConfig::SENDFILE_APACHE) {
            header('X-Sendfile: ' . $path);

            return;
        }

        header('X-Accel-Redirect: ' . $this->config->nginxInternalPrefix . '/' . $key);
    }

    private function stream(string $path, int $start, int $length): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            if ($start > 0) {
                fseek($handle, $start);
            }

            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(self::CHUNK, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
    }
}
