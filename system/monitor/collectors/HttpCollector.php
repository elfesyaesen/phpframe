<?php

declare(strict_types=1);

namespace System\Monitor\Collectors;

use System\Http\Request;
use System\Monitor\Contracts\CollectorInterface;
use System\Monitor\Contracts\ScrubberInterface;
use System\Monitor\RequestContext;
use System\Monitor\RequestTrace;

/**
 * HTTP isteği/yanıtı ve çalışma zamanı ölçümlerini toplar.
 */
final class HttpCollector implements CollectorInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly Request $request,
        private readonly ScrubberInterface $scrubber,
        private readonly int $maxBodyBytes,
        private readonly bool $captureRequestBody,
        private readonly bool $captureResponseBody,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function collect(RequestTrace $trace): void
    {
        $trace->method      = $this->serverValue('REQUEST_METHOD');
        $trace->uri         = $this->path();
        $trace->status      = $this->status();
        $trace->durationMs  = $this->context->elapsedMs();
        $trace->memoryKb    = (int) round(memory_get_peak_usage(true) / 1024);
        $trace->ip          = $this->clientIp();
        $trace->userUuid    = $this->request->userUuid();
        $trace->headers     = $this->headers();

        if ($this->captureRequestBody) {
            $trace->requestBody = $this->body($this->context->rawInput());
        }

        if ($this->captureResponseBody) {
            $trace->responseBody = $this->body($this->context->responseBody());
        }
    }

    /**
     * İstek yolu — query string ATILIR.
     *
     * Query string sırlar taşıyabilir (`?token=...`) ve aynı endpoint'in
     * kayıtlarını parametre değerlerine göre bölerek gruplamayı imkânsız
     * kılar. Parametreler gerekiyorsa maskelenmiş hâlde `headers`/`body`
     * yerine ayrıca ele alınmalıdır.
     */
    private function path(): string
    {
        $uri = $this->serverValue('REQUEST_URI');

        if ($uri === '') {
            return '/';
        }

        $path = strtok($uri, '?');

        return $path === false || $path === '' ? '/' : $path;
    }

    /**
     * Yanıt durum kodu.
     *
     * Fatal error durumunda bu değer henüz 500'e çekilmemiş olabilir; bunu
     * `FatalErrorCollector` düzeltir (collector sırası bu yüzden önemlidir).
     */
    private function status(): int
    {
        $code = http_response_code();

        return is_int($code) ? $code : 0;
    }

    /**
     * `Request::ip()` proxy-farkındadır: TRUSTED_PROXIES'e göre
     * X-Forwarded-For'u doğrular. Ham REMOTE_ADDR kullanmak load balancer
     * arkasında tüm istemcileri tek IP'ye çökertirdi.
     */
    private function clientIp(): ?string
    {
        $ip = $this->request->ip();

        return ($ip === null || $ip === '') ? null : $ip;
    }

    /**
     * İstek header'ları, maskelenmiş.
     *
     * `$_SERVER` doğrudan okunur, `Request::header()` üzerinden değil:
     * `Request` constructor'ında tüm girdiyi `htmlspecialchars`'tan geçiriyor
     * ve izleme kaydında header'ların ham hâli gerekiyor (teşhis için
     * `&amp;` görmek işe yaramaz).
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$this->normalizeHeaderName($name)] = $value;
                continue;
            }

            // Bu ikisi HTTP_ prefix'i almaz ama gerçek istek header'larıdır.
            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = str_replace('_', '-', $key);
                $headers[$this->normalizeHeaderName($name)] = $value;
            }
        }

        ksort($headers);

        /** @var array<string, string> $scrubbed */
        $scrubbed = $this->scrubber->scrub($headers);

        return $scrubbed;
    }

    private function normalizeHeaderName(string $name): string
    {
        return ucwords(strtolower($name), '-');
    }

    private function body(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        return $this->scrubber->scrubText($raw, $this->maxBodyBytes);
    }

    private function serverValue(string $key): string
    {
        $value = $_SERVER[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
