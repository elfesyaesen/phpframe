<?php

declare(strict_types=1);

namespace System\Config;

use InvalidArgumentException;

final readonly class MediaCollection
{
    public const PUBLIC  = 'public';
    public const PRIVATE = 'private';

    private const FORBIDDEN_MIMES = ['image/svg+xml', 'text/html', 'application/xhtml+xml', 'application/javascript', 'text/javascript'];

    /**
     * @param list<string> $mimes
     * @param array<string, int> $variants
     */
    public function __construct(
        public string $name,
        public string $visibility,
        public array $mimes,
        public int $maxBytes,
        public array $variants,
        public ?string $permission,
        public bool $uploadable,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(string $name, array $definition): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) !== 1) {
            throw new InvalidArgumentException("Medya koleksiyon adı geçersiz: '{$name}' (küçük harf, rakam, alt çizgi)");
        }

        $visibility = (string) ($definition['visibility'] ?? self::PRIVATE);
        if (!in_array($visibility, [self::PUBLIC, self::PRIVATE], true)) {
            throw new InvalidArgumentException("'{$name}' koleksiyonu: visibility public veya private olmalı.");
        }

        $mimes = array_values(array_map('strval', (array) ($definition['mimes'] ?? [])));
        if ($mimes === []) {
            throw new InvalidArgumentException("'{$name}' koleksiyonu: en az bir MIME türü tanımlanmalı.");
        }

        $forbidden = array_intersect($mimes, self::FORBIDDEN_MIMES);
        if ($forbidden !== []) {
            throw new InvalidArgumentException(
                "'{$name}' koleksiyonu: " . implode(', ', $forbidden) . ' kabul edilemez (betik taşıyabilir).'
            );
        }

        $variants = [];
        foreach ((array) ($definition['variants'] ?? []) as $variant => $size) {
            if (!is_string($variant) || preg_match('/^[a-z][a-z0-9_]{0,15}$/', $variant) !== 1 || $variant === 'file') {
                throw new InvalidArgumentException("'{$name}' koleksiyonu: varyant adı geçersiz.");
            }
            $variants[$variant] = max(16, (int) $size);
        }

        return new self(
            name: $name,
            visibility: $visibility,
            mimes: $mimes,
            maxBytes: max(1, (int) ($definition['max_mb'] ?? 10)) * 1024 * 1024,
            variants: $variants,
            permission: isset($definition['permission']) ? (string) $definition['permission'] : null,
            uploadable: (bool) ($definition['uploadable'] ?? true),
        );
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::PUBLIC;
    }

    public function allowsImages(): bool
    {
        foreach ($this->mimes as $mime) {
            if (str_starts_with($mime, 'image/')) {
                return true;
            }
        }

        return false;
    }
}
