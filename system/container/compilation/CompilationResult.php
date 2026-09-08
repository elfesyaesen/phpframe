<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Definition\Definition;

/**
 * Derleme koşusunun tüm sonucu.
 *
 * Hem `container:compile` hem `container:validate` bunu üretir; ikisi
 * arasındaki tek fark, validate'in kod üretip yazmamasıdır. Aynı sonucu
 * paylaşmaları kasıtlı: validate'in "temiz" dediği bir grafik compile'da
 * farklı bir sonuç veremez.
 */
final readonly class CompilationResult
{
    /**
     * @param array<string, Definition>          $definitions Derlenen tanımlar (id sıralı)
     * @param list<Diagnostic>                   $diagnostics Toplanan bulgular
     * @param array<string, string>              $roots       id => kök kaynağı
     * @param array<string, string>              $skipped     id => atlanma sebebi
     * @param array<string, list<string>>        $missing     eksik id => onu isteyenler
     * @param array<string, float|int>           $stats       süre/sayaç ölçümleri
     */
    public function __construct(
        public array $definitions,
        public DependencyGraph $graph,
        public array $diagnostics,
        public string $hash,
        public array $roots = [],
        public array $skipped = [],
        public array $missing = [],
        public array $stats = [],
        public ?string $source = null,
        public ?string $className = null,
    ) {}

    /** @return list<Diagnostic> */
    public function errors(): array
    {
        return $this->bySeverity(Severity::ERROR);
    }

    /** @return list<Diagnostic> */
    public function warnings(): array
    {
        return $this->bySeverity(Severity::WARNING);
    }

    /** @return list<Diagnostic> */
    public function notices(): array
    {
        return $this->bySeverity(Severity::NOTICE);
    }

    /** @return list<Diagnostic> */
    private function bySeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn(Diagnostic $d): bool => $d->severity === $severity
        ));
    }

    public function isSuccessful(): bool
    {
        return $this->errors() === [];
    }

    public function serviceCount(): int
    {
        return count($this->definitions);
    }

    /**
     * Metadata dosyasına yazılan yapı — `container:list/debug/graph` bunu
     * okur, çalışan istek ASLA okumaz (o yüzden boyutu bedava).
     *
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $services = [];

        foreach ($this->definitions as $id => $definition) {
            $services[$id] = [
                'concrete' => $definition->concrete,
                'lifetime' => $definition->lifetime->value,
                'construction' => $definition->describeConstruction(),
                'autowired' => $definition->autowired,
                'source' => $definition->source,
                'dependencies' => $definition->edges(),
                'dependents' => $this->graph->dependents($id),
                'root' => $this->roots[$id] ?? null,
            ];
        }

        return [
            'hash' => $this->hash,
            'class' => $this->className,
            'generated' => date('c'),
            'stats' => $this->stats,
            'services' => $services,
            'skipped' => $this->skipped,
            'missing' => $this->missing,
            'diagnostics' => array_map(
                static fn(Diagnostic $d): array => [
                    'severity' => $d->severity->value,
                    'code' => $d->code,
                    'claim' => $d->claim,
                    'chain' => $d->chain,
                    'service' => $d->service,
                    'source' => $d->source,
                ],
                $this->diagnostics
            ),
        ];
    }

    public function withGenerated(string $source, string $className): self
    {
        return new self(
            $this->definitions,
            $this->graph,
            $this->diagnostics,
            $this->hash,
            $this->roots,
            $this->skipped,
            $this->missing,
            $this->stats,
            $source,
            $className,
        );
    }
}
