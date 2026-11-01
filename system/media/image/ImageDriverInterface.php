<?php

declare(strict_types=1);

namespace System\Media\Image;

interface ImageDriverInterface
{
    public function name(): string;

    public function supports(string $format): bool;

    /**
     * @param string $path
     * @param string $mime
     * @param array<string, int> $targets
     * @param list<string> $formats
     * @return array{w: int, h: int, variants: array<string, array{w: int, h: int, files: array<string, string>}>}
     */
    public function process(string $path, string $mime, array $targets, array $formats, int $quality): array;
}
