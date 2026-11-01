<?php

declare(strict_types=1);

namespace System\Media\Contracts;

interface UploadScannerInterface
{
    /**
     * @return bool
     */
    public function isClean(string $path, string $mime): bool;
}
