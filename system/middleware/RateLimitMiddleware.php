<?php

declare(strict_types=1);

namespace System\Middleware;

use Closure;
use System\Config\RateLimitConfig;
use System\Middleware\Interface\MiddlewareInterface;
use System\Security\RateLimiter;
use System\Exceptions\TooManyRequestsException;
use System\Http\Request;
use System\Http\Response;

/**
 * Rate Limit Middleware
 *
 * İstek sayısını sınırlar. Kullanım:
 * - 'rate_limit' → Varsayılan: 60 istek/dakika
 * - 'rate_limit:100' → 100 istek/dakika
 * - 'rate_limit:100,120' → 100 istek/2 dakika
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * Bağımlılıklar açıkça enjekte edilir.
     *
     * İKİ DEĞİŞİKLİK VE GEREKÇELERİ:
     *
     * 1. `= new RateLimiter()` initializer default'u KALDIRILDI. PHP 8.1+
     *    default değerlerde `new` ifadesine izin verir, ama bu değer
     *    derlenmiş container dosyasına YAZILAMAZ (obje var_export edilemez).
     *    Derleyici bunu compile hatası olarak bildirir — doğru davranış,
     *    çünkü aksi halde require anında fatal veren kod üretilirdi.
     *    Bağımlılık artık provider'da açıkça bind edilir.
     *
     * 2. `ContainerAwareInterface`/`Trait` KALDIRILDI. Middleware container'a
     *    hiç ihtiyaç duymuyordu; sadece alias çözümleyicisi ona setter ile
     *    container enjekte ediyordu. Kaldırılması service locator yüzeyini
     *    daraltır (DI-plan §37).
     *
     * 3. `DEFAULT_MAX_ATTEMPTS`/`DEFAULT_DECAY_SECONDS` sabitleri yerine
     *    `RateLimitConfig`: limiti değiştirmek artık kod dağıtımı değil
     *    `.env` değişikliği.
     */
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
        // Parametreleri parse et
        [$maxAttempts, $decaySeconds] = $this->parseParameter($parameter);

        // Key oluştur (IP + route)
        $key = $this->getKey($request);

        // Rate limit kontrolü
        if (!$this->limiter->attempt($key, $maxAttempts, $decaySeconds)) {
            $retryAfter = $this->limiter->retryAfter($key);

            // Rate limit header'larını gönder
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

        // Başarılı istek - header'ları gönder
        $this->sendHeaders($key, $maxAttempts);

        $next($request);
    }

    /**
     * Parametre parse et
     *
     * @return array{0: int, 1: int} [maxAttempts, decaySeconds]
     */
    private function parseParameter(?string $parameter): array
    {
        if ($parameter === null || $parameter === '') {
            return [$this->defaults->maxAttempts, $this->defaults->decaySeconds];
        }

        $parts = explode(',', $parameter);

        $maxAttempts = isset($parts[0]) && is_numeric($parts[0])
            ? (int) $parts[0]
            : $this->defaults->maxAttempts;

        $decaySeconds = isset($parts[1]) && is_numeric($parts[1])
            ? (int) $parts[1]
            : $this->defaults->decaySeconds;

        return [$maxAttempts, $decaySeconds];
    }

    /**
     * Rate limit key oluştur
     */
    private function getKey(Request $request): string
    {
        $ip = $this->clientIp($request);
        $route = $_SERVER['REQUEST_URI'] ?? '/';

        // Query string'i çıkar
        $route = strtok($route, '?') ?: '/';

        return 'rate:' . $ip . ':' . md5($route);
    }

    /**
     * İstemci IP'sini proxy-farkında biçimde çözer.
     *
     * Ham `REMOTE_ADDR` kullanmak, bir load balancer/reverse proxy arkasında
     * tüm istemcileri proxy'nin tek IP'sine — dolayısıyla tek rate-limit
     * bucket'ına — çökertir ve per-client limiti etkisizleştirir. Bunun yerine
     * `Request::ip()` (güvenilen proxy listesine göre X-Forwarded-For
     * doğrular) kullanılır.
     *
     * Request artık CONTAINER'DAN ÇEKİLMİYOR: `handle()` onu zaten parametre
     * olarak alıyor. Eskiden `$this->container->get(Request::class)`
     * çağrılıyordu — elde olan bir nesneyi locator'dan yeniden istemek hem
     * gereksizdi hem de container set edilmediğinde sessizce REMOTE_ADDR'a
     * düşerek per-client limiti proxy arkasında etkisizleştirebiliyordu.
     */
    private function clientIp(Request $request): string
    {
        $ip = $request->ip();

        if ($ip !== null && $ip !== '') {
            return $ip;
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Rate limit header'larını gönder
     */
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
