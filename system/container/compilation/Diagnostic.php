<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use Throwable;

final readonly class Diagnostic
{
    /**
     * @param string $code
     * @param string $claim
     * @param list<string> $chain
     * @param string|null $marker
     * @param string|null $fix
     * @param string|null $service
     * @param string|null $source
     */
    public function __construct(
        public Severity $severity,
        public string $code,
        public string $claim,
        public array $chain = [],
        public ?string $marker = null,
        public ?string $fix = null,
        public ?string $service = null,
        public ?string $source = null,
    ) {}

    public static function error(
        string $code,
        string $claim,
        array $chain = [],
        ?string $marker = null,
        ?string $fix = null,
        ?string $service = null,
        ?string $source = null,
    ): self {
        return new self(Severity::ERROR, $code, $claim, $chain, $marker, $fix, $service, $source);
    }

    public static function warning(
        string $code,
        string $claim,
        array $chain = [],
        ?string $marker = null,
        ?string $fix = null,
        ?string $service = null,
        ?string $source = null,
    ): self {
        return new self(Severity::WARNING, $code, $claim, $chain, $marker, $fix, $service, $source);
    }

    public static function notice(
        string $code,
        string $claim,
        array $chain = [],
        ?string $marker = null,
        ?string $fix = null,
        ?string $service = null,
        ?string $source = null,
    ): self {
        return new self(Severity::NOTICE, $code, $claim, $chain, $marker, $fix, $service, $source);
    }

    public static function fromThrowable(Throwable $e, ?string $service = null, ?string $source = null): self
    {
        $code = $e instanceof \System\Container\Exception\ContainerException
            ? $e->errorCode
            : 'RESOLUTION_FAILED';

        $message = $e->getMessage();

        $claim = $message;
        if (preg_match('/^\[[A-Z_]+\]\s*(.*)$/s', $message, $m) === 1) {
            $claim = $m[1];
        }

        return new self(
            Severity::ERROR,
            $code,
            $claim,
            service: $service,
            source: $source,
        );
    }

    public function isError(): bool
    {
        return $this->severity === Severity::ERROR;
    }

    public function render(): string
    {
        $out = "[{$this->code}] {$this->claim}";

        if ($this->chain !== []) {
            $out .= "\n";
            $last = count($this->chain) - 1;
            foreach ($this->chain as $i => $line) {
                $prefix = $i === 0 ? '  ' : '   → ';
                $suffix = ($i === $last && $this->marker !== null) ? '  ✗ ' . $this->marker : '';
                $out .= "\n" . $prefix . $line . $suffix;
            }
        }

        if ($this->source !== null && $this->chain === []) {
            $out .= "\n\n  tanım: " . $this->source;
        }

        if ($this->fix !== null) {
            $out .= "\n\n  " . str_replace("\n", "\n  ", $this->fix);
        }

        return $out;
    }

    public function summary(): string
    {
        $first = strtok($this->claim, "\n");

        return '[' . $this->code . '] ' . ($first === false ? $this->claim : $first);
    }

    /**
     * @return array<string, mixed>
     */
    public function toContext(): array
    {
        return [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'service' => $this->service,
            'source' => $this->source,
            'chain' => $this->chain,
        ];
    }
}
