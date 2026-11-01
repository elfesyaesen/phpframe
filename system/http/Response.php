<?php

declare(strict_types=1);

namespace System\Http;

use System\Helpers\Status;
use System\Http\Contract\LocaleProviderInterface;
use JsonException;
use LogicException;

class Response
{
    private bool $sent = false;

    public function __construct(
        private readonly LocaleProviderInterface $localeProvider,
    ) {}

    private function markSent(string $method): void
    {
        if ($this->sent) {
            throw new LogicException(sprintf(
                'Yanıt zaten gönderildi; %s() ikinci kez çağrıldı. '
                . 'Muhtemel sebep: bir guard cümlesinden sonra `return` unutulmuş.',
                $method
            ));
        }

        $this->sent = true;
    }

    /**
     * @param int|Status $status
     * @param mixed $data
     * @throws JsonException
     */
    public function jsonResponse(int|Status $status, mixed $data): void
    {
        $this->markSent(__FUNCTION__);

        if ($status instanceof Status) {
            $status = $status->value;
        }

        SecurityHeaders::send();

        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        header('Content-Type: application/json; charset=utf-8');

        $this->sendContentLanguageHeader();

        http_response_code($status);

        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        echo $json;
    }

    private function sendContentLanguageHeader(): void
    {
        try {
            header('Content-Language: ' . $this->localeProvider->locale());
        } catch (\Throwable) {
        }
    }

    public function htmlResponse(int|Status $status, string $content): void
    {
        $this->markSent(__FUNCTION__);

        if ($status instanceof Status) {
            $status = $status->value;
        }

        SecurityHeaders::send();

       header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; frame-ancestors 'self';");

        header('Content-Type: text/html; charset=utf-8');
        http_response_code($status);

        echo $content;
    }

    public function redirect(string $url, int $status = 302, bool $allowExternal = false): void
    {
        $this->markSent(__FUNCTION__);

        if (!self::isSafeRedirectTarget($url, $allowExternal)) {
            $url = '/';
        }

        http_response_code($status);
        header('Location: ' . $url);
    }

    public static function isSafeRedirectTarget(string $url, bool $allowExternal): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        if ($url[0] === '/') {
            return !isset($url[1]) || ($url[1] !== '/' && $url[1] !== '\\');
        }

        if (!$allowExternal) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
