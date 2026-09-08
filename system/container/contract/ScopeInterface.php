<?php

declare(strict_types=1);

namespace System\Container\Contract;

/**
 * Bir scope: SCOPED servislerin örneklerini tutan, sonunda kapatılan kap
 * (DI-plan §7, §8).
 *
 * HTTP'de bir istek = bir scope. PHP-FPM'de bir worker ardışık birçok isteği
 * karşıladığı için, per-request mutable state'in SINGLETON değil SCOPED olması
 * ve scope'un istek sonunda dispose edilmesi zorunludur.
 */
interface ScopeInterface
{
    /** Scope adı ('http', 'cli', ...) — hata mesajlarında ve trace'te görünür. */
    public function name(): string;

    /**
     * Servisi bu scope bağlamında çözer. Container'a delege eder; SCOPED
     * servisler bu scope'ta saklanır.
     */
    public function get(string $id): mixed;

    public function has(string $id): bool;

    /**
     * Örnek bu scope'ta varsa döndürür, yoksa factory ile üretip saklar.
     *
     * @param callable():mixed $factory
     */
    public function remember(string $id, callable $factory): mixed;

    /** Hazır bir örneği bu scope'a yerleştirir (eager kurulmuş servisler için). */
    public function set(string $id, mixed $instance): void;

    /**
     * Scope'u kapatır: DisposableInterface uygulayan örnekleri TERS oluşturma
     * sırasıyla kapatır, sonra temizler.
     *
     * IDEMPOTENT olmak ZORUNDA: Response::jsonResponse() ve
     * BaseController::view() içindeki `exit` yüzünden dispose hem `finally`
     * bloğundan hem shutdown hook'undan çağrılabilir.
     */
    public function dispose(): void;

    public function isDisposed(): bool;
}
