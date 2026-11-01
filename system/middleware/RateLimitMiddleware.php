<?php

declare(strict_types=1);

namespace System\Middleware;

use Closure;
use System\Config\RateLimitConfig;
use System\Middleware\Interface\MiddlewareInterface;
use System\Security\RateLimiter;
use System\Exceptions\InternalServerException;
use System\Exceptions\TooManyRequestsException;
use System\Http\Request;
use System\Http\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly RateLimitConfig $defaults,
    ) {}

    /**
     * @throws TooManyRequestsException
     */
    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        [$maxAttempts, $decaySeconds] = $this->parseParameter($parameter);

        $key = $this->getKey($request) . ":{$maxAttempts}/{$decaySeconds}";

        if (!$this->limiter->attempt($key, $maxAttempts, $decaySeconds)) {
            $retryAfter = $this->limiter->retryAfter($key);

            $this->sendHeaders($key, $maxAttempts, $retryAfter);

            throw new TooManyRequestsException(
                'Çok fazla istek gönderdiniz',null,
                [
                    'retry_after' => $retryAfter,
                    'limit' => $maxAttempts,
                    'window' => $decaySeconds,
                ]
            );
        }

        $this->sendHeaders($key, $maxAttempts);

        $next($request);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseParameter(?string $parameter): array
    {
        if ($parameter === null || $parameter === '') {
            return [$this->defaults->maxAttempts, $this->defaults->decaySeconds];
        }

        if (preg_match('/^([1-9]\d*)(?:,([1-9]\d*))?$/', $parameter, $m) !== 1) {
            throw new InternalServerException(
                message: "Geçersiz rate_limit parametresi: '{$parameter}' (beklenen: 'adet' veya 'adet,saniye', pozitif tam sayı)",
                context: ['parameter' => $parameter],
            );
        }

        return [(int) $m[1], isset($m[2]) ? (int) $m[2] : $this->defaults->decaySeconds];
    }

    private function getKey(Request $request): string
    {
        $route    = $request->matchedRoute() ?? $this->normalizedPath($request);
        $userUuid = $request->userUuid();
        $identity = $userUuid !== null ? 'user:' . $userUuid : $this->clientIp($request);

        return 'rate:' . $identity . ':' . hash('sha256', $route);
    }

    private function normalizedPath(Request $request): string
    {
        $path = $request->path();

        $script = (string) ($request->server('SCRIPT_NAME') ?? '');
        if ($script !== '' && str_starts_with($path, $script)) {
            $path = substr($path, strlen($script));
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return strtoupper($request->method()) . ' /' . strtolower(trim($path, '/'));
    }

    private function clientIp(Request $request): string
    {
        $ip = $request->ip();

        if ($ip !== null && $ip !== '') {
            return $ip;
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private function sendHeaders(string $key, int $maxAttempts, ?int $retryAfter = null): void
    {
        $headers = $this->limiter->getHeaders($key, $maxAttempts);

        header('X-RateLimit-Limit: ' . $headers['X-RateLimit-Limit']);
        header('X-RateLimit-Remaining: ' . $headers['X-RateLimit-Remaining']);
        header('X-RateLimit-Reset: ' . $headers['X-RateLimit-Reset']);

        if ($retryAfter !== null) {
            header('Retry-After: ' . $retryAfter);
        }
    }
}
