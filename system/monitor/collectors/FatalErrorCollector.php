<?php

declare(strict_types=1);

namespace System\Monitor\Collectors;

use System\Monitor\Contracts\CollectorInterface;
use System\Monitor\RequestTrace;

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
