<?php

declare(strict_types=1);

namespace System\Exceptions;

use Exception;
use System\Helpers\Status;
use Throwable;

abstract class HttpException extends Exception
{
    public function __construct(
        string $message = '',
        protected readonly int $statusCode = 500,
        protected array $headers = [],
        protected array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Hata gövdesi.
     *
     * ── GEÇİŞ ZARFI ──────────────────────────────────────────────────────
     * `error` nesnesinin YANINDA üst düzey bir `message` anahtarı da yazılır.
     *
     * Neden: API bugün REDDEDEN KATMANA GÖRE iki farklı hata biçimi
     * döndürüyor — `RoleMiddleware`/`PermissionMiddleware` istisna fırlatıp
     * `{"error":{...}}` üretirken, controller'lar ve
     * `AuthMiddleware::unauthorized()` `{"message":"..."}` üretiyor.
     * Controller guard'ları istisnalara taşınırken bu tutarsızlık
     * tekilleştiriliyor; ancak `.message` okuyan mevcut istemcilerin
     * kırılmaması için her iki anahtar bir süre birlikte yayınlanır.
     *
     * Frontend `.error.message`'a geçtiğinde aşağıdaki tek satır silinir.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function toArray(bool $debug = false): array
    {
        $data = [
            'error' => [
                'code' => $this->statusCode,
                'message' => $this->getMessage(),
            ],
            // Geçiş anahtarı — yukarıdaki nota bakınız.
            'message' => $this->getMessage(),
        ];

        if (!empty($this->context)) {
            $data['error']['details'] = $this->sanitizeContext($this->context);
        }

        if ($debug) {
            $data['error']['debug'] = [
                'exception' => static::class,
                // Tam path yerine sadece dosya adı (güvenlik için)
                'file' => basename($this->getFile()),
                'line' => $this->getLine(),
                // Trace'de hassas bilgileri filtrele
                'trace' => $this->sanitizeTrace(array_slice($this->getTrace(), 0, 10)),
            ];
        }

        return $data;
    }

    /**
     * Context'i sanitize et (hassas verileri filtrele)
     */
    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'auth', 'credential', 'api_key'];
        $result = [];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Hassas key'leri maskele
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $result[$key] = '***FILTERED***';
                    continue 2;
                }
            }

            // HTML KAÇIŞI UYGULANMAZ — bilinçli.
            //
            // Bu gövde `JsonRenderer` tarafından `application/json` olarak
            // yayınlanıyor; `htmlspecialchars` bir HTML bağlamı önlemi ve
            // JSON'da yalnızca veriyi BOZAR. Ölçülen sonuç:
            //   "Kullanıcı'nın kaydı & bulunamadı"
            //   → "Kullanıcı&apos;nın kaydı &amp; bulunamadı"
            // Türkçe kesme işareti içeren her mesaj bu şekilde bozuluyordu.
            //
            // Doğru kaçış katmanı `json_encode()`'dur (zaten uygulanıyor) ve
            // yanıt `X-Content-Type-Options: nosniff` ile gönderilir. Gövdeyi
            // HTML'e gömen bir istemci kaçışı KENDİ bağlamında yapmalıdır.
            if (is_array($value)) {
                $result[$key] = $this->sanitizeContext($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Stack trace'i sanitize et
     */
    private function sanitizeTrace(array $trace): array
    {
        return array_map(function (array $frame): array {
            return [
                'file' => isset($frame['file']) ? basename($frame['file']) : null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                // Args'ı dahil etme (hassas veri içerebilir)
            ];
        }, $trace);
    }

    public static function fromStatus(Status $status, string $message = '', array $context = []): static
    {
        return new static($message ?: $status->name, $status->value, [], $context);
    }
}
