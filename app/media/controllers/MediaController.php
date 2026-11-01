<?php

declare(strict_types=1);

namespace App\Media\Controllers;

use System\Engine\BaseController;
use System\Exceptions\ForbiddenException;
use System\Exceptions\NotFoundException;
use System\Helpers\Uuid;
use System\Media\MediaResponder;
use System\Media\MediaService;
use System\Media\UrlSigner;
use System\Translation\Contract\TranslatorInterface;

final class MediaController extends BaseController
{
    private const FILE_PATTERN = '/^([a-z][a-z0-9_]{0,15})\.([a-z0-9]{2,5})$/';

    public function __construct(
        private readonly MediaService $Media,
        private readonly MediaResponder $Responder,
        private readonly UrlSigner $Signer,
        private readonly TranslatorInterface $Translator,
    ) {}

    public function publicFile(string $uuid, string $file): void
    {
        [$variant, $format] = $this->parseFile($uuid, $file);

        $media = $this->Media->find($uuid);

        if ($media === null || !$media->isPublic()) {
            $this->notFound();
        }

        if (!$this->Responder->send($media, $variant, $format, null, $_SERVER)) {
            $this->notFound();
        }
    }

    public function signedFile(string $uuid, string $file): void
    {
        [$variant, $format] = $this->parseFile($uuid, $file);

        $request = $this->request();
        $expires = (string) ($request->query('exp', '') ?? '');
        $sig     = (string) ($request->query('sig', '') ?? '');

        if (!ctype_digit($expires) || !$this->Signer->verify($uuid, $variant . '.' . $format, (int) $expires, $sig)) {
            throw new ForbiddenException($this->Translator->trans('media.link_invalid'));
        }

        $media = $this->Media->find($uuid);

        if ($media === null || !$this->Responder->send($media, $variant, $format, (int) $expires, $_SERVER)) {
            $this->notFound();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseFile(string $uuid, string $file): array
    {
        if (!Uuid::isValid($uuid) || preg_match(self::FILE_PATTERN, $file, $m) !== 1) {
            $this->notFound();
        }

        return [$m[1], $m[2]];
    }

    private function notFound(): never
    {
        throw new NotFoundException($this->Translator->trans('media.not_found'));
    }
}
