<?php

declare(strict_types=1);

namespace System\Runtime;

use Closure;
use Throwable;

/**
 * Öncelikli shutdown hook kaydı — TEK `register_shutdown_function` sahibi.
 *
 * NEDEN TEK: PHP'de bir shutdown fonksiyonu `exit()` çağırırsa sıradaki
 * shutdown fonksiyonları hiç çalışmaz. Birden fazla ayrı kayıt varken bu,
 * "hangisi önce kaydedildi" sorusunu bir doğruluk invariant'ı hâline getirir
 * ve o invariant yalnızca bir yorumla korunabilir.
 *
 * Tek fonksiyon + öncelik sırası bunu yapısal olarak çözer: `exit()` yapan bir
 * hook, kendisinden ÖNCE gelmesi gereken hook'ları engelleyemez çünkü onlar
 * aynı fonksiyon içinde çoktan çalışmıştır (bkz. ShutdownPriority).
 */
final class ShutdownRegistry
{
    /** @var array<int, list<Closure>> öncelik => hook'lar */
    private array $hooks = [];

    private bool $armed = false;
    private bool $ran = false;

    /**
     * Hook kaydeder ve gerekiyorsa PHP shutdown fonksiyonunu kurar.
     *
     * @param int              $priority Yüksek olan önce çalışır (bkz. ShutdownPriority)
     * @param Closure(): void  $hook
     */
    public function on(int $priority, Closure $hook): void
    {
        $this->hooks[$priority][] = $hook;

        $this->arm();
    }

    /**
     * PHP shutdown fonksiyonunu kurar. İlk `on()` çağrısına kadar HİÇ
     * kaydedilmez: hiçbir hook yoksa (örn. monitor kapalı, CLI) shutdown
     * maliyeti tam olarak sıfır kalır.
     */
    private function arm(): void
    {
        if ($this->armed) {
            return;
        }

        $this->armed = true;

        register_shutdown_function($this->run(...));
    }

    /**
     * Hook'ları önceliğe göre azalan sırada çalıştırır.
     *
     * Her hook try/catch ile sarılır: bir hook'un patlaması diğerlerini
     * engellemez. Teardown sırasında logger'ın kendisi kapanmış olabileceği
     * için burada loglama YAPILMAZ.
     */
    public function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        krsort($this->hooks);

        foreach ($this->hooks as $group) {
            foreach ($group as $hook) {
                try {
                    $hook();
                } catch (Throwable) {
                    // Sessizce yut — bir hook'un hatası diğerlerini kesmemeli.
                }
            }
        }
    }

    /** Kaydedilmiş hook sayısı (teşhis için). */
    public function count(): int
    {
        return array_sum(array_map('count', $this->hooks));
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }
}
