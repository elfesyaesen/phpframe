<?php

declare(strict_types=1);

namespace System\Media;

final class MediaResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Media $media, MediaUrlGenerator $urls): array
    {
        $resource = [
            'uuid'          => $media->uuid,
            'collection'    => $media->collection,
            'visibility'    => $media->visibility,
            'mime'          => $media->mime,
            'size'          => $media->size,
            'width'         => $media->width,
            'height'        => $media->height,
            'original_name' => $media->originalName,
            'created_at'    => $media->createdAt,
            'urls'          => $urls->urls($media),
        ];

        if ($media->isImage()) {
            $resource['srcset'] = $urls->srcset($media);

            $avif = [];
            foreach (array_keys($media->variants) as $variant) {
                $url = $urls->url($media, (string) $variant, 'avif');
                if ($url !== null) {
                    $avif[(string) $variant] = $url;
                }
            }

            if ($avif !== []) {
                $resource['alternates'] = ['avif' => $avif];
            }
        }

        $resource['expires_at'] = $urls->expiresAt($media);

        return $resource;
    }
}
