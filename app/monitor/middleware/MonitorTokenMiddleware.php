<?php

declare(strict_types=1);

namespace App\Monitor\Middleware;

use Closure;
use System\Config\MonitorConfig;
use System\Exceptions\NotFoundException;
use System\Http\Request;
use System\Http\Response;
use System\Middleware\Interface\MiddlewareInterface;

final class MonitorTokenMiddleware implements MiddlewareInterface
{
    private const HEADER = 'X-Monitor-Token';
    private const COOKIE = 'monitor_token';
    private const QUERY  = 'token';

    private const COOKIE_PATH = '/monitor';

    public function __construct(
        private readonly MonitorConfig $monitor,
    ) {}

    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        $expected = $this->monitor->token;

        if ($expected === '') {
            $this->deny('monitor_token_not_configured');
        }

        $header = $request->header(self::HEADER);
        if (is_string($header) && $header !== '' && hash_equals($expected, $header)) {
            $next($request);

            return;
        }

        $query = $request->query(self::QUERY);
        if (is_string($query) && $query !== '' && hash_equals($expected, $query)) {
            $this->storeCookie($query);
            $response->redirect($this->cleanUrl());

            return;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '' && hash_equals($expected, $cookie)) {
            $next($request);

            return;
        }

        $this->deny('monitor_token_mismatch');
    }

    private function storeCookie(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'expires'  => 0,
            'path'     => self::COOKIE_PATH,
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private function cleanUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/monitor';
        $uri = is_string($uri) ? $uri : '/monitor';

        $path  = strtok($uri, '?');
        $path  = ($path === false || $path === '') ? '/monitor' : $path;

        $path  = '/' . ltrim($path, '/\\');
        $queryString = parse_url($uri, PHP_URL_QUERY);

        if (!is_string($queryString) || $queryString === '') {
            return $path;
        }

        parse_str($queryString, $params);
        unset($params[self::QUERY]);

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private function deny(string $reason): never
    {
        throw new NotFoundException(
            message: 'Sayfa bulunamadı',
            context: ['reason' => $reason]
        );
    }
}
