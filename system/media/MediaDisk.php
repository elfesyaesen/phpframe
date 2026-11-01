<?php

declare(strict_types=1);

namespace System\Media;

use System\Storage\FileStorageInterface;

final class MediaDisk implements FileStorageInterface
{
    public function __construct(
        private readonly FileStorageInterface $inner,
    ) {}

    public function put(string $key, string $contents): void
    {
        $this->inner->put($key, $contents);
    }

    public function putFromUpload(string $key, array $uploadedFile): void
    {
        $this->inner->putFromUpload($key, $uploadedFile);
    }

    public function get(string $key): ?string
    {
        return $this->inner->get($key);
    }

    public function stream(string $key, ?string $downloadName = null): void
    {
        $this->inner->stream($key, $downloadName);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function size(string $key): int
    {
        return $this->inner->size($key);
    }

    public function mimeType(string $key): ?string
    {
        return $this->inner->mimeType($key);
    }

    public function localPath(string $key): ?string
    {
        return $this->inner->localPath($key);
    }
}
