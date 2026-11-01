<?php

declare(strict_types=1);

namespace System\Media;

use JsonException;

final readonly class Media
{
    public const FILE_VARIANT = 'file';

    public const FULL_VARIANT = 'full';

    /**
     * @param array<string, array{w: ?int, h: ?int, files: array<string, array{key: string, mime: string, bytes: int, etag: string}>}> $variants
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $collection,
        public string $visibility,
        public ?string $ownerType,
        public ?string $ownerRef,
        public string $mime,
        public int $size,
        public ?int $width,
        public ?int $height,
        public string $sha256,
        public ?string $originalName,
        public array $variants,
        public ?string $createdBy,
        public string $createdAt,
        public ?string $deletedAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     * @throws JsonException
     */
    public static function fromRow(array $row): self
    {
        $variants = $row['variants'] ?? [];
        if (is_string($variants)) {
            $variants = json_decode($variants, true, 8, JSON_THROW_ON_ERROR);
        }

        return new self(
            id: (int) ($row['id'] ?? 0),
            uuid: (string) $row['uuid'],
            collection: (string) $row['collection'],
            visibility: (string) $row['visibility'],
            ownerType: isset($row['owner_type']) ? (string) $row['owner_type'] : null,
            ownerRef: isset($row['owner_ref']) ? (string) $row['owner_ref'] : null,
            mime: (string) $row['mime'],
            size: (int) $row['size'],
            width: isset($row['width']) ? (int) $row['width'] : null,
            height: isset($row['height']) ? (int) $row['height'] : null,
            sha256: (string) $row['sha256'],
            originalName: isset($row['original_name']) ? (string) $row['original_name'] : null,
            variants: is_array($variants) ? $variants : [],
            createdBy: isset($row['created_by']) ? (string) $row['created_by'] : null,
            createdAt: (string) ($row['created_at'] ?? ''),
            deletedAt: isset($row['deleted_at']) ? (string) $row['deleted_at'] : null,
        );
    }

    public function isPublic(): bool
    {
        return $this->visibility === 'public';
    }

    public function isImage(): bool
    {
        return !isset($this->variants[self::FILE_VARIANT]);
    }

    /**
     * @return array{key: string, mime: string, bytes: int, etag: string}|null
     */
    public function file(string $variant, string $format): ?array
    {
        return $this->variants[$variant]['files'][$format] ?? null;
    }

    public function defaultFormat(string $variant): ?string
    {
        $files = $this->variants[$variant]['files'] ?? [];

        if (isset($files['webp'])) {
            return 'webp';
        }

        $first = array_key_first($files);

        return $first === null ? null : (string) $first;
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->variants as $variant) {
            foreach ($variant['files'] ?? [] as $file) {
                $keys[] = (string) $file['key'];
            }
        }

        return $keys;
    }
}
