<?php

declare(strict_types=1);

namespace System\Media;

use System\Media\Contracts\UploadScannerInterface;

final class NullScanner implements UploadScannerInterface
{
    public function isClean(string $path, string $mime): bool
    {
        return true;
    }
}
