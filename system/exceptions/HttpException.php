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

    public function toArray(bool $debug = false): array
    {
        $expose = $debug || $this->statusCode < 500;
        $message = $expose ? $this->getMessage() : 'Sunucu hatası';

        $data = [
            'error' => [
                'code' => $this->statusCode,
                'message' => $message,
            ],
            'message' => $message,
        ];

        if ($debug && !empty($this->context)) {
            $data['error']['details'] = $this->sanitizeContext($this->context);
        }

        if ($debug) {
            $data['error']['debug'] = [
                'exception' => static::class,
                'file' => basename($this->getFile()),
                'line' => $this->getLine(),
                'trace' => $this->sanitizeTrace(array_slice($this->getTrace(), 0, 10)),
            ];
        }

        return $data;
    }

    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'auth', 'credential', 'api_key'];
        $result = [];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);

            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $result[$key] = '***FILTERED***';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $result[$key] = $this->sanitizeContext($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function sanitizeTrace(array $trace): array
    {
        return array_map(function (array $frame): array {
            return [
                'file' => isset($frame['file']) ? basename($frame['file']) : null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ];
        }, $trace);
    }

    public static function fromStatus(Status $status, string $message = '', array $context = []): static
    {
        return new static($message ?: $status->name, $status->value, [], $context);
    }
}
