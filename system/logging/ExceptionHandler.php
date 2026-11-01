<?php

declare(strict_types=1);

namespace System\Logging;

use ErrorException;
use System\Exceptions\HttpException;
use System\Exceptions\InternalServerException;
use System\Http\SecurityHeaders;
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

    private readonly ScrubberInterface $scrubber;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
        ?ErrorRendererInterface $renderer = null,
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
     * @param ShutdownRegistry|null $shutdown
     */
    public function register(?ShutdownRegistry $shutdown = null): void
    {
        if (!$this->debug) {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
        }

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
        try {
            $this->recorder?->recordException($original);
        } catch (Throwable) {
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
            SecurityHeaders::send();
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
