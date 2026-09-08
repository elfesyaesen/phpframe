<?php

declare(strict_types=1);

namespace System\Container\Binding;

/**
 * id → target yönlendirme kenarı (DI-plan §11).
 *
 * Tipik kullanım interface binding'dir:
 *   PaymentInterface → StripePayment
 *
 * Derlenmiş container'da bu kenar KAYBOLUR: interface id'si doğrudan somut
 * sınıfın kurucu metoduna map'lenir, yani runtime'da interface lookup sıfırdır
 * (DI-plan §11 son satırı).
 */
final readonly class Binding
{
    /**
     * @param class-string $id
     * @param class-string $target
     * @param string|null  $source Bind'in yazıldığı yer (file:line)
     */
    public function __construct(
        public string $id,
        public string $target,
        public ?string $source = null,
    ) {}
}
