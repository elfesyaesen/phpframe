<?php

declare(strict_types=1);

namespace System\Http;

use System\Config\HttpConfig;

use JsonException;

class Request
{
    public readonly array $headers;
    public readonly array $files;
    public readonly array $server;
    public readonly array $request;
    private ?array $jsonCache = null;
    private ?string $userUuid = null;
    private ?array $authUser = null;
    private ?string $matchedRoute = null;
    private ?string $matchedRouteName = null;

    private TrustedProxies $trustedProxies;

    public function __construct(?HttpConfig $http = null)
    {
        $this->trustedProxies = new TrustedProxies($http?->trustedProxies ?? ['127.0.0.1', '::1']);

        $this->server  = $_SERVER;
        $this->headers = $this->parseHeaders();
        $this->files   = $_FILES;
        $this->request = $this->sanitize(
            [...$_GET, ...$_POST, ...$this->parseJsonBody()]
        );
    }

    public function files(string $key): array|null
    {
        return $this->files[$key] ?? null;
    }

    public function server(string $key): string|null
    {
        return $this->server[$key] ?? null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    public function header(string $key): string|null
    {
        if (isset($this->headers[$key])) {
            return $this->headers[$key];
        }

        foreach ($this->headers as $name => $value) {
            if (strcasecmp((string) $name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function all(): array
    {
        return $this->request;
    }

    public function has(string $key): bool
    {
        return isset($this->request[$key]) && $this->request[$key] !== '';
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->request, array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->request, array_flip($keys));
    }

    public function json(): array
    {
        return $this->parseJsonBody();
    }

    public function setUserUuid(string $uuid): void
    {
        $this->userUuid = $uuid;
    }

    public function userUuid(): ?string
    {
        return $this->userUuid;
    }


    /**
     * @param array<string, mixed> $user
     */
    public function setAuthUser(array $user): void
    {
        $this->authUser = $user;
    }


    /**
     * @return array<string, mixed>|null
     */
    public function authUser(): ?array
    {
        return $this->authUser;
    }

    public function setMatchedRoute(string $method, string $pattern, ?string $name = null): void
    {
        $this->matchedRoute     = strtoupper($method) . ' ' . $pattern;
        $this->matchedRouteName = $name;
    }

    public function matchedRoute(): ?string
    {
        return $this->matchedRoute;
    }

    public function matchedRouteName(): ?string
    {
        return $this->matchedRouteName;
    }

    public function token(): string|null
    {
        $header = $this->header('Authorization')
            ?? $this->server('REDIRECT_HTTP_AUTHORIZATION')
            ?? '';

        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function ip(): string|null
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? null;

        if ($remoteAddr === null) {
            return null;
        }

        if ($this->isTrustedProxy($remoteAddr)) {
            $forwarded = $this->server['HTTP_X_FORWARDED_FOR'] ?? null;

            if ($forwarded !== null && $forwarded !== '') {
                $ips = array_map('trim', explode(',', $forwarded));

                for ($i = count($ips) - 1; $i >= 0; $i--) {
                    if (!$this->isValidIp($ips[$i])) {
                        return $this->isValidIp($remoteAddr) ? $remoteAddr : null;
                    }

                    if (!$this->isTrustedProxy($ips[$i])) {
                        return $ips[$i];
                    }
                }

                return $ips[0];
            }

            $realIp = $this->server['HTTP_X_REAL_IP'] ?? null;
            if ($realIp !== null && $this->isValidIp($realIp)) {
                return $realIp;
            }
        }

        return $this->isValidIp($remoteAddr) ? $remoteAddr : null;
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function isTrustedProxy(string $ip): bool
    {
        return $this->trustedProxies->contains($ip);
    }

    /**
     * @param array<string> $proxies
     */
    public function setTrustedProxies(array $proxies): void
    {
        $this->trustedProxies = new TrustedProxies(array_values($proxies));
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';

        $position = strpos($uri, '?');
        if ($position !== false) {
            $uri = substr($uri, 0, $position);
        }

        $uri = rawurldecode($uri);

        return $uri === '' ? '/' : $uri;
    }

    public function method(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($_GET)[$key] ?? $default;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query($key, $default);
    }

    public function post(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'POST') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }

    public function put(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'PUT') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }

    public function patch(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'PATCH') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }

    public function delete(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'DELETE') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }

    private function parseHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', substr($key, 5));
                $header = ucwords(strtolower($header), '-');
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    private function parseJsonBody(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $content = file_get_contents('php://input');

        if ($content === '' || $content === false) {
            return $this->jsonCache = [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return $this->jsonCache = is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return $this->jsonCache = [];
        }
    }

    private function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $safeKey = $this->sanitizeKey($key);

            if (is_array($value)) {
                $result[$safeKey] = $this->sanitize($value);

            } elseif (is_string($value)) {
                $result[$safeKey] = $this->sanitizeString($value);

            } elseif ($value === null) {
                $result[$safeKey] = '';

            } else {
                $result[$safeKey] = $value;
            }
        }

        return $result;
    }

    private function sanitizeString(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $hasControlOrInvalid = preg_match(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u',
            $value
        );
        if ($hasControlOrInvalid === 0) {
            return $value;
        }

        $value = str_replace("\0", '', $value);

        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        $value = preg_replace('/[\x{0080}-\x{009F}]/u', '', $value);

        return $value;
    }

    private function sanitizeKey(string|int $key): string|int
    {
        if (is_int($key)) {
            return $key;
        }

        $key = str_replace("\0", '', $key);
        $key = preg_replace('/[\x00-\x1F\x7F]/', '', $key);

        return $key;
    }
}
