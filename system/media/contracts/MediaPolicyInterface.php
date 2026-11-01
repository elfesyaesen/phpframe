<?php

declare(strict_types=1);

namespace System\Media\Contracts;

use System\Media\Media;

interface MediaPolicyInterface
{
    public function canView(?string $userUuid, Media $media): bool;

    public function canDelete(?string $userUuid, Media $media): bool;
}
