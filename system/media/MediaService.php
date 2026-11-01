<?php

declare(strict_types=1);

namespace System\Media;

use System\Config\MediaCollection;
use System\Config\MediaConfig;
use System\Helpers\Uuid;
use System\Media\Contracts\UploadScannerInterface;
use System\Media\Image\ImageProcessor;
use System\Storage\StorageException;
use System\Storage\UploadValidator;
use Throwable;

final class MediaService
{
    public function __construct(
        private readonly MediaConfig $config,
        private readonly MediaRepository $repository,
        private readonly MediaDisk $disk,
        private readonly UploadValidator $validator,
        private readonly ImageProcessor $images,
        private readonly UploadScannerInterface $scanner,
    ) {}

    /**
     * @param array<string, mixed> $file
     * @param bool $viaPublicApi
     * @throws MediaException
     */
    public function store(
        array $file,
        string $collectionName,
        ?string $userUuid,
        ?string $ownerType = null,
        ?string $ownerRef = null,
        bool $viaPublicApi = true,
    ): Media {
        $collection = $this->config->collection($collectionName)
            ?? throw new MediaException(MediaException::UNKNOWN_COLLECTION, "Bilinmeyen koleksiyon: {$collectionName}");

        if ($viaPublicApi && !$collection->uploadable) {
            throw new MediaException(MediaException::NOT_UPLOADABLE, "Koleksiyon genel uçtan yüklenemez: {$collectionName}");
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size > $collection->maxBytes) {
            throw new MediaException(MediaException::TOO_LARGE, "Dosya {$size} bayt, sınır {$collection->maxBytes}.");
        }

        try {
            $mime = $this->validator->validate($file, $collection->mimes, $collection->maxBytes);
        } catch (StorageException $e) {
            throw new MediaException(MediaException::INVALID_FILE, $e->getMessage(), $e);
        }

        $this->assertQuota($userUuid, $size);

        $tmp = (string) $file['tmp_name'];
        if (!$this->scanner->isClean($tmp, $mime)) {
            throw new MediaException(MediaException::SCAN_REJECTED, 'Tarayıcı dosyayı reddetti.');
        }

        $uuid   = Uuid::generate();
        $prefix = $collection->visibility . '/' . gmdate('Y/m') . '/' . $uuid . '/';

        [$record, $written] = str_starts_with($mime, 'image/')
            ? $this->storeImage($tmp, $mime, $collection, $prefix)
            : $this->storeFile($file, $mime, $prefix);

        $record += [
            'uuid'          => $uuid,
            'collection'    => $collection->name,
            'visibility'    => $collection->visibility,
            'owner_type'    => $ownerType,
            'owner_ref'     => $ownerRef,
            'original_name' => self::cleanName((string) ($file['name'] ?? '')),
            'created_by'    => $userUuid,
        ];

        try {
            $this->repository->insert($record);
        } catch (Throwable $e) {
            $this->discard($written);

            throw new MediaException(MediaException::STORAGE_FAILED, 'Medya kaydı yazılamadı.', $e);
        }

        return $this->repository->find($uuid)
            ?? throw new MediaException(MediaException::STORAGE_FAILED, 'Yazılan medya kaydı okunamadı.');
    }

    public function find(string $uuid): ?Media
    {
        return Uuid::isValid($uuid) ? $this->repository->find($uuid) : null;
    }

    public function delete(Media $media): bool
    {
        return $this->repository->softDelete($media->uuid);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function storeImage(string $tmp, string $mime, MediaCollection $collection, string $prefix): array
    {
        $processed = $this->images->process($tmp, $mime, $collection->variants);

        $variants = [];
        $written  = [];
        $total    = 0;
        $fullHash = '';

        try {
            foreach ($processed['variants'] as $name => $variant) {
                $files = [];

                foreach ($variant['files'] as $format => $bytes) {
                    $key = $prefix . $name . '.' . $format;
                    $this->disk->put($key, $bytes);
                    $written[] = $key;

                    $hash   = hash('sha256', $bytes);
                    $total += strlen($bytes);

                    if ($name === Media::FULL_VARIANT && $format === 'webp') {
                        $fullHash = $hash;
                    }

                    $files[$format] = [
                        'key'   => $key,
                        'mime'  => 'image/' . $format,
                        'bytes' => strlen($bytes),
                        'etag'  => substr($hash, 0, 32),
                    ];
                }

                $variants[$name] = ['w' => $variant['w'], 'h' => $variant['h'], 'files' => $files];
            }
        } catch (Throwable $e) {
            $this->discard($written);

            throw new MediaException(MediaException::STORAGE_FAILED, 'Resim varyantı yazılamadı.', $e);
        }

        return [[
            'mime'     => 'image/webp',
            'size'     => $total,
            'width'    => $variants[Media::FULL_VARIANT]['w'] ?? $processed['w'],
            'height'   => $variants[Media::FULL_VARIANT]['h'] ?? $processed['h'],
            'sha256'   => $fullHash,
            'variants' => json_encode($variants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], $written];
    }

    /**
     * @param array<string, mixed> $file
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function storeFile(array $file, string $mime, string $prefix): array
    {
        $extension = $this->validator->extensionFor($mime);
        $key       = $prefix . Media::FILE_VARIANT . '.' . $extension;
        $hash      = hash_file('sha256', (string) $file['tmp_name']) ?: '';
        $size      = (int) $file['size'];

        try {
            $this->disk->putFromUpload($key, $file);
        } catch (StorageException $e) {
            throw new MediaException(MediaException::STORAGE_FAILED, 'Dosya yazılamadı.', $e);
        }

        $variants = [Media::FILE_VARIANT => [
            'w'     => null,
            'h'     => null,
            'files' => [$extension => [
                'key'   => $key,
                'mime'  => $mime,
                'bytes' => $size,
                'etag'  => substr($hash, 0, 32),
            ]],
        ]];

        return [[
            'mime'     => $mime,
            'size'     => $size,
            'width'    => null,
            'height'   => null,
            'sha256'   => $hash,
            'variants' => json_encode($variants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], [$key]];
    }

    private function assertQuota(?string $userUuid, int $incoming): void
    {
        if ($userUuid === null || $this->config->userQuotaMb === 0) {
            return;
        }

        $limit = $this->config->userQuotaMb * 1024 * 1024;

        if ($this->repository->usedBytes($userUuid) + $incoming > $limit) {
            throw new MediaException(MediaException::QUOTA_EXCEEDED, 'Kullanıcı kotası aşıldı.');
        }
    }

    private static function cleanName(string $name): ?string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name);

        if ($name === '' || !mb_check_encoding($name, 'UTF-8')) {
            return null;
        }

        return mb_substr($name, 0, 255);
    }

    /** @param list<string> $keys */
    private function discard(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->disk->delete($key);
            } catch (Throwable) {
            }
        }
    }
}
