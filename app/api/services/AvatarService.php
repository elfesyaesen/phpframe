<?php

declare(strict_types=1);

namespace App\Api\Services;

use App\Api\Models\UserModel;
use System\Exceptions\InternalServerException;
use System\Exceptions\NotFoundException;
use System\Exceptions\ServiceUnavailableException;
use System\Exceptions\ValidationException;
use System\Media\Media;
use System\Media\MediaException;
use System\Media\MediaResource;
use System\Media\MediaResponder;
use System\Media\MediaService;
use System\Media\MediaUrlGenerator;
use System\Storage\FileStorageInterface;
use System\Translation\Contract\TranslatorInterface;
use Throwable;

final class AvatarService
{
    private const COLLECTION = 'avatar';

    private const SERVED_VARIANT = 'medium';

    public function __construct(
        private readonly UserModel $users,
        private readonly MediaService $media,
        private readonly MediaUrlGenerator $urls,
        private readonly MediaResponder $responder,
        private readonly FileStorageInterface $legacyStorage,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws ValidationException
     * @throws ServiceUnavailableException
     * @throws InternalServerException
     */
    public function upload(string $userUuid, mixed $file): void
    {
        if (!is_array($file)) {
            throw $this->invalid('user.avatar.required');
        }

        $refs = $this->users->getAvatarRefs($userUuid)
            ?? throw new NotFoundException($this->translator->trans('user.not_found'));

        try {
            $media = $this->media->store($file, self::COLLECTION, $userUuid, 'user', $userUuid, viaPublicApi: false);
        } catch (MediaException $e) {
            throw $this->translate($e);
        }

        if (!$this->users->updateAvatarMedia($userUuid, $media->uuid)) {
            $this->media->delete($media);

            throw new InternalServerException($this->translator->trans('user.avatar.save_failed'));
        }

        $this->discardOld($refs);
    }

    /**
     * @throws NotFoundException
     */
    public function remove(string $userUuid): void
    {
        $refs = $this->users->getAvatarRefs($userUuid);

        if ($refs === null || ($refs['media_uuid'] === null && $refs['legacy_key'] === null)) {
            throw new NotFoundException($this->translator->trans('user.avatar.not_found'));
        }

        if (!$this->users->updateAvatarMedia($userUuid, null)) {
            throw new InternalServerException($this->translator->trans('user.avatar.save_failed'));
        }

        $this->discardOld($refs);
    }

    /**
     * @throws NotFoundException
     */
    public function stream(string $userUuid): void
    {
        $refs      = $this->users->getAvatarRefs($userUuid);
        $mediaUuid = $refs['media_uuid'] ?? null;
        $media     = $mediaUuid !== null ? $this->media->find($mediaUuid) : null;

        if ($media !== null) {
            $format = $media->defaultFormat(self::SERVED_VARIANT) ?? 'webp';

            if ($this->responder->send($media, self::SERVED_VARIANT, $format, time(), $_SERVER)) {
                return;
            }
        }

        $legacy = $refs['legacy_key'] ?? null;

        if ($legacy !== null && $this->legacyStorage->exists($legacy)) {
            $this->legacyStorage->stream($legacy);

            return;
        }

        throw new NotFoundException($this->translator->trans('user.avatar.not_found'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resource(?string $mediaUuid): ?array
    {
        if ($mediaUuid === null || $mediaUuid === '') {
            return null;
        }

        $media = $this->media->find($mediaUuid);

        return $media === null ? null : MediaResource::toArray($media, $this->urls);
    }

    /**
     * @param array{media_uuid: ?string, legacy_key: ?string} $refs
     */
    private function discardOld(array $refs): void
    {
        try {
            if ($refs['media_uuid'] !== null) {
                $old = $this->media->find($refs['media_uuid']);

                if ($old instanceof Media) {
                    $this->media->delete($old);
                }
            }

            if ($refs['legacy_key'] !== null) {
                $this->legacyStorage->delete($refs['legacy_key']);
            }
        } catch (Throwable) {
        }
    }

    private function translate(MediaException $e): Throwable
    {
        return match (true) {
            $e->reason === MediaException::DRIVER_UNAVAILABLE => new ServiceUnavailableException(
                $this->translator->trans('media.unavailable'),
                previous: $e,
            ),
            !$e->isClientError() => new InternalServerException(
                $this->translator->trans('user.avatar.save_failed'),
                previous: $e,
            ),
            default => $this->invalid(match ($e->reason) {
                MediaException::TOO_LARGE       => 'media.file_too_large',
                MediaException::TOO_MANY_PIXELS => 'media.image_too_large',
                MediaException::QUOTA_EXCEEDED  => 'media.quota_exceeded',
                default                         => 'user.avatar.required',
            }, $e),
        };
    }

    private function invalid(string $key, ?Throwable $previous = null): ValidationException
    {
        $message = $this->translator->trans($key);

        return new ValidationException(['avatar' => [$message]], $message, previous: $previous);
    }
}
