<?php

declare(strict_types=1);

namespace App\Media\Controllers;

use App\Api\Security\Gate;
use System\Config\MediaConfig;
use System\Engine\BaseController;
use System\Exceptions\ForbiddenException;
use System\Exceptions\HttpException;
use System\Exceptions\InternalServerException;
use System\Exceptions\NotFoundException;
use System\Exceptions\ServiceUnavailableException;
use System\Exceptions\ValidationException;
use System\Helpers\Status;
use System\Media\Contracts\MediaPolicyInterface;
use System\Media\Media;
use System\Media\MediaException;
use System\Media\MediaResource;
use System\Media\MediaService;
use System\Media\MediaUrlGenerator;
use System\Translation\Contract\TranslatorInterface;

final class MediaApiController extends BaseController
{
    public function __construct(
        private readonly MediaService $Media,
        private readonly MediaUrlGenerator $Urls,
        private readonly MediaPolicyInterface $Policy,
        private readonly MediaConfig $Config,
        private readonly Gate $Gate,
        private readonly TranslatorInterface $Translator,
    ) {}

    public function store(): void
    {
        $request    = $this->request();
        $userUuid   = (string) $request->userUuid();
        $collection = (string) ($request->input('collection', '') ?? '');
        $file       = $request->files('file');

        $definition = $this->Config->collection($collection);
        if ($definition === null || !$definition->uploadable) {
            throw new ValidationException(
                ['collection' => [$this->Translator->trans('media.collection_invalid')]],
                $this->Translator->trans('media.collection_invalid'),
            );
        }

        if ($definition->permission !== null && $this->Gate->denies($definition->permission)) {
            throw new ForbiddenException($this->Translator->trans('media.upload_forbidden'));
        }

        if (!is_array($file)) {
            throw new ValidationException(
                ['file' => [$this->Translator->trans('media.file_required')]],
                $this->Translator->trans('media.file_required'),
            );
        }

        try {
            $media = $this->Media->store($file, $collection, $userUuid);
        } catch (MediaException $e) {
            throw $this->translate($e);
        }

        $this->response()->jsonResponse(Status::CREATED, MediaResource::toArray($media, $this->Urls));
        return;
    }

    public function show(string $uuid): void
    {
        $media = $this->visible($uuid);

        $this->response()->jsonResponse(Status::OK, MediaResource::toArray($media, $this->Urls));
        return;
    }

    public function destroy(string $uuid): void
    {
        $media = $this->visible($uuid);

        if (!$this->Policy->canDelete($this->request()->userUuid(), $media)) {
            throw new ForbiddenException($this->Translator->trans('media.delete_forbidden'));
        }

        $this->Media->delete($media);

        $this->response()->jsonResponse(Status::OK, []);
        return;
    }

    private function visible(string $uuid): Media
    {
        $media = $this->Media->find($uuid);

        if ($media === null || !$this->Policy->canView($this->request()->userUuid(), $media)) {
            throw new NotFoundException($this->Translator->trans('media.not_found'));
        }

        return $media;
    }

    private function translate(MediaException $e): HttpException
    {
        $key = match ($e->reason) {
            MediaException::UNKNOWN_COLLECTION,
            MediaException::NOT_UPLOADABLE     => 'media.collection_invalid',
            MediaException::TOO_LARGE          => 'media.file_too_large',
            MediaException::TOO_MANY_PIXELS    => 'media.image_too_large',
            MediaException::QUOTA_EXCEEDED     => 'media.quota_exceeded',
            MediaException::SCAN_REJECTED      => 'media.scan_rejected',
            MediaException::DRIVER_UNAVAILABLE => 'media.unavailable',
            MediaException::STORAGE_FAILED     => 'media.save_failed',
            default                            => 'media.file_invalid',
        };

        $message = $this->Translator->trans($key);

        if ($e->reason === MediaException::DRIVER_UNAVAILABLE) {
            return new ServiceUnavailableException($message, previous: $e);
        }

        if (!$e->isClientError()) {
            return new InternalServerException($message, previous: $e);
        }

        $field = in_array($e->reason, [MediaException::UNKNOWN_COLLECTION, MediaException::NOT_UPLOADABLE], true)
            ? 'collection'
            : 'file';

        return new ValidationException([$field => [$message]], $message, previous: $e);
    }
}
