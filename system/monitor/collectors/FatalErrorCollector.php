<?php

declare(strict_types=1);

namespace System\Monitor\Collectors;

use System\Monitor\Contracts\CollectorInterface;
use System\Monitor\RequestTrace;

/**
 * Fatal error'ları ize ekler ve durum kodunu düzeltir.
 *
 * Neden ayrı bir collector: fırlatılan `Throwable`'lar `ExceptionHandler`
 * üzerinden `Recorder::recordException()`'a doğrudan ulaşır. Ama fatal
 * error'lar (bellek tükenmesi, `E_PARSE`, olmayan fonksiyon çağrısı) hiçbir
 * zaman `Throwable` olmaz — yalnızca `error_get_last()` ile görülebilirler.
 *
 * İkinci ve daha ince görev: DURUM KODU DÜZELTMESİ. Monitor'ün shutdown
 * fonksiyonu `ExceptionHandler::register()`'dan ÖNCE kaydedilmek zorundadır
 * (aksi halde `ExceptionHandler::handleShutdown()` içindeki `exit(1)`,
 * PHP'nin shutdown zincirini kesip monitor'ü hiç çalıştırmaz — tam olarak
 * fatal error durumunda). Bunun bedeli, monitor çalıştığında henüz
 * `http_response_code(500)` çağrılmamış olmasıdır. Burada telafi edilir:
 * fatal varsa durum 500'e normalize edilir, böylece dashboard ve
 * "hatalar her zaman saklanır" örnekleme kuralı doğru davranır.
 */
final class FatalErrorCollector implements CollectorInterface
{
    private const FATAL_ERRORS = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    public function name(): string
    {
        return 'fatal_error';
    }

    public function collect(RequestTrace $trace): void
    {
        $error = error_get_last();

        if ($error === null || !in_array($error['type'], self::FATAL_ERRORS, true)) {
            return;
        }

        $trace->exceptions[] = [
            'class'   => $this->severityToString($error['type']),
            'message' => (string) $error['message'],
            'file'    => (string) $error['file'],
            'line'    => (int) $error['line'],
            'trace'   => [],
        ];

        if ($trace->status < 500) {
            $trace->status = 500;
        }
    }

    private function severityToString(int $severity): string
    {
        return match ($severity) {
            E_ERROR         => 'E_ERROR',
            E_PARSE         => 'E_PARSE',
            E_CORE_ERROR    => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            default         => 'E_UNKNOWN',
        };
    }
}
