<?php

declare(strict_types=1);

namespace System\Logging;

use Closure;
use System\Logging\Contracts\LoggerInterface;
use Throwable;

/**
 * Aktif scope'un logger'ına delege eden SINGLETON cephe.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN VAR:
 *
 * `LoggerInterface` SCOPED'dır çünkü her isteğin log satırları o isteğin
 * `request_id`'sini taşımak zorunda (log ↔ monitor korelasyonu). Ama bazı
 * SINGLETON servisler de loglamak ister:
 *
 *   • ExceptionHandler   — global handler, süreç boyunca bir kez kurulur
 *   • DatabaseStorage    — monitor deposu, worker ömrü boyunca yaşar
 *
 * Scoped logger'ı bunlara doğrudan enjekte etmek DI-plan §19'un yasakladığı
 * Singleton → Scoped kenarıdır: 1. isteğin logger'ı (ve onun request_id'si)
 * sonsuza pinlenir ve o worker'ın gördüğü TÜM isteklerin hataları 1. isteğin
 * kimliğiyle loglanır. Bu, hataları teşhis etmek için en çok ihtiyaç
 * duyulan bilgiyi sessizce yanlış yapar.
 *
 * Locator her çağrıda aktif scope'un logger'ını çözer; scope yoksa
 * (boot, shutdown sonrası, CLI'ın erken safhası) taban logger'a düşer —
 * log satırı request_id taşımaz ama KAYBEDİLMEZ.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class LoggerLocator implements LoggerInterface
{
    /**
     * @param Closure(): LoggerInterface $resolver Aktif scope'un logger'ını çözer
     * @param Logger $fallback Scope yokken kullanılacak taban logger
     */
    public function __construct(
        private readonly Closure $resolver,
        private readonly Logger $fallback,
    ) {}

    /**
     * Aktif logger; çözülemezse taban logger.
     */
    private function active(): LoggerInterface
    {
        try {
            $logger = ($this->resolver)();
        } catch (Throwable) {
            return $this->fallback;
        }

        // Kendine delege etmeye karşı koruma: locator yanlışlıkla
        // LoggerInterface bağlaması olarak kaydedilirse sonsuz özyineleme
        // olurdu.
        return $logger === $this ? $this->fallback : $logger;
    }

    /**
     * DİKKAT: bu cephe üzerinde `withContext()` çağırmak, ALTTAKİ logger'ı
     * değiştirir (Logger::withContext mutating bir builder'dır). Singleton
     * bir servisin per-request context yazması tam olarak bu sınıfın önlemek
     * için var olduğu hatadır, o yüzden burada değişiklik yapılmaz ve cephe
     * kendisini döndürür.
     */
    public function withContext(array $context): self
    {
        return $this;
    }

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $this->active()->log($level, $message, $context);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->active()->emergency($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->active()->alert($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->active()->critical($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->active()->error($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->active()->warning($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->active()->notice($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->active()->info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->active()->debug($message, $context);
    }
}
