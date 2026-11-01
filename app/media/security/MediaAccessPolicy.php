<?php

declare(strict_types=1);

namespace App\Media\Security;

use App\Api\Security\Gate;
use System\Media\Contracts\MediaPolicyInterface;
use System\Media\Media;

final class MediaAccessPolicy implements MediaPolicyInterface
{
    public function __construct(
        private readonly Gate $gate,
    ) {}

    public function canView(?string $userUuid, Media $media): bool
    {
        if ($media->isPublic()) {
            return true;
        }

        if ($userUuid === null) {
            return false;
        }

        return $media->createdBy === $userUuid || $this->gate->allows('media.view_any');
    }

    public function canDelete(?string $userUuid, Media $media): bool
    {
        if ($userUuid === null) {
            return false;
        }

        return $media->createdBy === $userUuid || $this->gate->allows('media.delete_any');
    }
}
