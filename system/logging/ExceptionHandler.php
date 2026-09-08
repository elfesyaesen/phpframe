<?php

declare(strict_types=1);

namespace System\Logging;

use ErrorException;
use System\Exceptions\HttpException;
use System\Exceptions\InternalServerException;
use System\Logging\Contracts\LoggerInterface;
use System\Logging\ErrorRenderer\ErrorRendererInterface;
use System\Logging\ErrorRenderer\JsonRenderer;
use System\Monitor\Contracts\RecorderInterface;
use System\Monitor\Contracts\ScrubberInterface;
use System\Monitor\Scrubber\KeyScrubber;
use System\Runtime\ShutdownPriority;
use System\Runtime\ShutdownRegistry;
use Throwable;

final class ExceptionHandler
{
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    private ErrorRendererInterface $renderer;

    /**
     * Hassas veri maskeleyici.
     *
     * Eskiden bu mantık bu sınıfın private `maskSensitive()` metodunda ve
     * private `SENSITIVE_KEYS` sabitinde duruyordu. Monitor de aynı işi yapmak
     * zorunda olduğu için (istek/yanıt gövdesi, header'lar, sorgu parametreleri)
     * `ScrubberInterface` arkasına çıkarıldı: TEK maskeleme kaynağı olması,
     * "bir kanalda maskeleniyor ama diğerinde sızıyor" hatasını yapısal olarak
     * imkânsız kılar. Varsayılan anahtar listesi eski sabiti kapsar.
     */
    private readonly ScrubberInterface $scrubber;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
        ?ErrorRendererInterface $renderer = null,
        /**
         * Monitor kaydı. Fırlatılan Throwable'lar buradan izin exception
         * listesine geçer. Monitor kapalıyken null'dır ve bu sınıfın davranışı
         * eskisiyle birebir aynı kalır.
         */
        private readonly ?RecorderInterface $recorder = null,
        ?ScrubberInterface $scrubber = null
    ) {
        $this->renderer = $renderer ?? new JsonRenderer();
        $this->scrubber = $scrubber ?? new KeyScrubber();
    }

    public function setRenderer(ErrorRendererInterface $renderer): void
    {
        $this->renderer = $renderer;
    }

    /**
     * Global handler'ları kurar.
     *
     * @param ShutdownRegistry|null $shutdown Verilirse fatal-error hook'u
     *        DOĞRUDAN `register_shutdown_function` ile değil, öncelikli
     *        registry üzerinden kaydedilir.
     *
     * NEDEN ÖNEMLİ: `handleShutdown()` bir fatal error gördüğünde yanıtı
     * gönderip `exit(1)` yapar. PHP'de bir shutdown fonksiyonu `exit()`
     * çağırdığında SIRADAKİ shutdown fonksiyonları hiç çalışmaz. Doğrudan
     * kayıt kullanıldığında, monitor'ün kaydını yazıp yazamaması "hangisi
     * önce register edildi" sorusuna — yani bootstrap satır sırasına —
     * bağlı kalır ve tam olarak en çok ihtiyaç duyulan durumda (fatal error)
     * gözlemlenebilirlik sessizce kaybolur.
     *
     * Registry verildiğinde sıra ShutdownPriority'de VERİ olarak durur:
     * MONITOR_FLUSH (100) her hâlükârda FATAL_RESPONSE (50)'den önce çalışır.
     */
    public function register(?ShutdownRegistry $shutdown = null): void
    {
        set_exception_handler($this->handleException(...));
        set_error_handler($this->handleError(...));

        if ($shutdown === null) {
            register_shutdown_function($this->handleShutdown(...));

            return;
        }

        $shutdown->on(ShutdownPriority::FATAL_RESPONSE, $this->handleShutdown(...));
    }

    public function handleException(Throwable $e): void
    {
        $httpException = $this->normalizeException($e);

        $this->logException($httpException, $e);
        $this->sendResponse($httpException);
    }

    private function normalizeException(Throwable $e): HttpException
    {
        if ($e instanceof HttpException) {
            return $e;
        }

        return InternalServerException::wrap($e);
    }

    private function logException(HttpException $httpException, Throwable $original): void
    {
        // Monitor'ün izine ekle. Bu çağrı exception fırlatmaz (sözleşme gereği),
        // ama monitor'ün bir hatası asla hata raporlamasını engellememeli.
        try {
            $this->recorder?->recordException($original);
        } catch (Throwable) {
            // Sessizce geç: elde loglanacak asıl hata var.
        }

        $level = $this->getLogLevel($httpException->getStatusCode());
        $context = [
            'type' => $original::class,
            'message' => $original->getMessage(),
            'code' => $httpException->getStatusCode(),
            'file' => $original->getFile(),
            'line' => $original->getLine(),
            'context' => $this->scrubber->scrub($httpException->getContext()),
            'trace' => $this->safeTrace($original),
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        match ($level) {
            'emergency' => $this->logger->emergency($original->getMessage(), $context),
            'critical' => $this->logger->critical($original->getMessage(), $context),
            'error' => $this->logger->error($original->getMessage(), $context),
            'warning' => $this->logger->warning($original->getMessage(), $context),
            default => $this->logger->info($original->getMessage(), $context),
        };
    }

    /**
     * Stack trace'i args OLMADAN döndürür. getTrace()'in 'args' alanı çağrı
     * parametrelerini (parola, token, SECRET_KEY vb.) içerir ve loglanmamalıdır.
     *
     * @return array<int, array{file: ?string, line: ?int, function: ?string, class: ?string}>
     */
    private function safeTrace(Throwable $e): array
    {
        return array_map(
            static fn (array $frame): array => [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                // 'args' KASTEN dahil edilmez (hassas veri sızıntısı)
            ],
            array_slice($e->getTrace(), 0, 10)
        );
    }

    private function getLogLevel(int $statusCode): string
    {
        return match (true) {
            $statusCode >= 500 => 'critical',
            $statusCode === 429 => 'warning',
            $statusCode >= 400 => 'warning',
            default => 'info',
        };
    }

    private function sendResponse(HttpException $exception): never
    {
        if (headers_sent()) {
            exit(1);
        }

        http_response_code($exception->getStatusCode());

        foreach ($exception->getHeaders() as $name => $value) {
            header("{$name}: {$value}");
        }

        header('Content-Type: ' . $this->renderer->getContentType());

        $this->sendSecurityHeaders();

        echo $this->renderer->render($exception, $this->debug);
        exit(1);
    }

    private function sendSecurityHeaders(): void
    {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            // Legacy XSS auditor kapalı (Response::sendSecurityHeaders ile tutarlı).
            header('X-XSS-Protection: 0');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
    }

    private function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $this->logger->error('PHP Error', [
            'severity' => $this->severityToString($severity),
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ]);

        // Deprecation/notice'leri exception'a ÇEVİRME: PHP 8.5'te artan deprecation'lar
        // (ve üçüncü-parti/legacy kod) aksi halde fatal akışa döner. Bunları logla-ve-devam et;
        // yalnızca gerçek hata sınıflarında exception fırlat.
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED, E_NOTICE, E_USER_NOTICE], true)) {
            return true;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    private function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL_ERRORS, true)) {
            return;
        }

        $this->logger->emergency('Fatal Error', [
            'severity' => $this->severityToString($error['type']),
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ]);

        $exception = new InternalServerException(
            message: $this->debug ? $error['message'] : 'Sunucu hatası',
            context: [
                'type' => 'fatal_error',
                'severity' => $this->severityToString($error['type']),
            ]
        );

        $this->sendResponse($exception);
    }

    private function severityToString(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'UNKNOWN',
        };
    }
}
