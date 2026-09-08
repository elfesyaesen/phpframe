<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use Throwable;

/**
 * Derleme sırasında toplanan tek bir bulgu.
 *
 * NEDEN İSTİSNA DEĞİL: compiler ilk hatada durup fırlatsaydı, 12 problemi
 * olan bir grafiği düzeltmek 12 derleme koşusu alırdı. Bulgular TOPLANIR,
 * tek koşuda hepsi raporlanır.
 *
 * Aynı yapı üç yerde render edilir — CLI tablosu, log context'i ve
 * CompilationException mesajı — ki geliştirici nereden bakarsa aynı bilgiyi
 * görsün (DI-plan §29).
 */
final readonly class Diagnostic
{
    /**
     * @param string       $code    Makine kodu, örn. 'SINGLETON_CAPTURES_SCOPED'
     * @param string       $claim   Tek satır iddia
     * @param list<string> $chain   Biçimlenmiş zincir satırları
     * @param string|null  $marker  Son zincir satırındaki ✗ işareti
     * @param string|null  $fix     Çözüm önerisi (çok satırlı olabilir)
     * @param string|null  $service Bulgunun ait olduğu servis id'si
     * @param string|null  $source  Tanımın kaynak konumu (file:line)
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

    /**
     * Çözümleme sırasında fırlatılan bir istisnayı Diagnostic'e çevirir.
     *
     * Resolver istisna fırlatır (dev runtime'da doğru davranış); compiler
     * onları yakalayıp toplar. İstisnanın mesajı zaten tam biçimli olduğu
     * için ham gövde olarak taşınır — iki farklı biçim üretip birinin
     * bakımını unutmaktan iyidir.
     */
    public static function fromThrowable(Throwable $e, ?string $service = null, ?string $source = null): self
    {
        $code = $e instanceof \System\Container\Exception\ContainerException
            ? $e->errorCode
            : 'RESOLUTION_FAILED';

        $message = $e->getMessage();

        // İstisna mesajı zaten "[CODE] ..." ile başlıyorsa öneki sıyır;
        // render() onu yeniden ekleyecek.
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

    /**
     * İstisna mesajlarıyla AYNI biçimde render eder (DI-plan §29).
     */
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

    /** Tek satırlık özet (CLI tablosu için). */
    public function summary(): string
    {
        $first = strtok($this->claim, "\n");

        return '[' . $this->code . '] ' . ($first === false ? $this->claim : $first);
    }

    /**
     * Logger context'i olarak yapılandırılmış hâli.
     *
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
