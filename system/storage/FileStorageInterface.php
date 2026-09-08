<?php

declare(strict_types=1);

namespace System\Storage;

interface FileStorageInterface
{
    public function put(string $key, string $contents): void;

    public function putFromUpload(string $key, array $uploadedFile): void;

    public function get(string $key): ?string;

    public function stream(string $key, ?string $downloadName = null): void;

    public function delete(string $key): void;

    public function exists(string $key): bool;

    public function size(string $key): int;

    public function mimeType(string $key): ?string;
}
