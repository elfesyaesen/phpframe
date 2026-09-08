<?php

declare(strict_types=1);

namespace System\Container\Observability;

use System\Container\Lifetime\Lifetime;

/**
 * Container çözümleme sayaçları (DI-plan §28).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * PRODUCTION'DA VARSAYILAN: KAPALI.
 *
 * Ve "kapalı" burada gerçekten kapalı demek: derlenmiş container bu sınıfı
 * HİÇ YÜKLEMEZ. Sayaç mekanizmasını derlenmiş kodun içine gömmek (kapalıyken
 * bile bir `if` bırakmak) DI-plan §16'nın "reflection = 0, lookup = 0"
 * hedefiyle çelişirdi: her servis çözümlemesine bir dal eklenirdi.
 *
 * Dolayısıyla observability DEV ARACIDIR. Ölçtüğü şeyler zaten dev'de
 * anlamlıdır:
 *   • hangi servis kaç kez çözülüyor (beklenmedik tekrar = yanlış lifetime)
 *   • transient olarak işaretlenmiş ama tek kez çözülen servisler
 *   • singleton olarak işaretlenmiş ama her istekte yeniden kurulan
 *     servisler (scope sızıntısının tersi)
 *   • çözümleme süresi
 * ─────────────────────────────────────────────────────────────────────────
 */
final class ResolutionRecorder
{
    /** @var array<string, int> id => çözümleme sayısı */
    private array $resolutions = [];

    /** @var array<string, int> id => yeni örnek üretme sayısı */
    private array $instantiations = [];

    /** @var array<string, float> id => toplam kurulum süresi (ms) */
    private array $durations = [];

    /** @var array<string, string> id => lifetime */
    private array $lifetimes = [];

    private int $scopesCreated = 0;
    private int $scopesDisposed = 0;

    /**
     * `get()` çağrısı — cache hit de miss de sayılır.
     */
    public function recordResolution(string $id): void
    {
        $this->resolutions[$id] = ($this->resolutions[$id] ?? 0) + 1;
    }

    /**
     * Yeni örnek kuruldu (cache miss).
     */
    public function recordInstantiation(string $id, Lifetime $lifetime, float $durationMs): void
    {
        $this->instantiations[$id] = ($this->instantiations[$id] ?? 0) + 1;
        $this->durations[$id] = ($this->durations[$id] ?? 0.0) + $durationMs;
        $this->lifetimes[$id] = $lifetime->value;
    }

    public function recordScopeCreated(): void
    {
        $this->scopesCreated++;
    }

    public function recordScopeDisposed(): void
    {
        $this->scopesDisposed++;
    }

    /**
     * Ölçüm özeti, en çok kurulan servis başta.
     *
     * @return list<array{
     *     id: string,
     *     lifetime: string,
     *     resolutions: int,
     *     instantiations: int,
     *     ms: float,
     *     note: string
     * }>
     */
    public function report(): array
    {
        $rows = [];

        foreach ($this->instantiations as $id => $count) {
            $resolutions = $this->resolutions[$id] ?? $count;
            $lifetime = $this->lifetimes[$id] ?? '?';

            $rows[] = [
                'id' => $id,
                'lifetime' => $lifetime,
                'resolutions' => $resolutions,
                'instantiations' => $count,
                'ms' => round($this->durations[$id] ?? 0.0, 3),
                'note' => $this->noteFor($lifetime, $resolutions, $count),
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => $b['instantiations'] <=> $a['instantiations']
        );

        return $rows;
    }

    /**
     * Sayaçlardan çıkarılabilecek şüpheli durumlar.
     *
     * Bunlar HATA DEĞİL — lifetime seçimi bir tasarım kararıdır ve bazen
     * kasıtlı olarak "verimsiz" görünür. Ama fark edilmeden kalması da
     * istenmez.
     */
    private function noteFor(string $lifetime, int $resolutions, int $instantiations): string
    {
        // Paylaşılması gereken bir servis birden fazla kez mi kuruldu?
        if ($instantiations > 1 && ($lifetime === 'singleton' || $lifetime === 'instance')) {
            return 'DİKKAT: paylaşılan servis ' . $instantiations . ' kez kuruldu';
        }

        // Transient olarak işaretlenmiş ama hep tek örnek — paylaşılabilir mi?
        if ($lifetime === 'transient' && $instantiations === 1 && $resolutions === 1) {
            return 'transient ama tek kez istendi — singleton olabilir';
        }

        // Aynı istekte çok kez kurulan transient: pahalıysa dikkat.
        if ($lifetime === 'transient' && $instantiations > 5) {
            return $instantiations . ' kez kuruldu — kurulum maliyetini ölç';
        }

        return '';
    }

    /**
     * @return array<string, int|float>
     */
    public function totals(): array
    {
        return [
            'resolutions' => array_sum($this->resolutions),
            'instantiations' => array_sum($this->instantiations),
            'distinct_services' => count($this->instantiations),
            'total_ms' => round(array_sum($this->durations), 3),
            'scopes_created' => $this->scopesCreated,
            'scopes_disposed' => $this->scopesDisposed,
        ];
    }

    /**
     * Açılmış ama kapatılmamış scope var mı?
     *
     * Sızdıran scope, scoped servislerin process sonuna kadar bellekte
     * kalması demektir; uzun ömürlü CLI worker'larında bellek büyür.
     */
    public function hasLeakedScopes(): bool
    {
        return $this->scopesCreated > $this->scopesDisposed;
    }

    public function reset(): void
    {
        $this->resolutions = [];
        $this->instantiations = [];
        $this->durations = [];
        $this->lifetimes = [];
        $this->scopesCreated = 0;
        $this->scopesDisposed = 0;
    }
}
