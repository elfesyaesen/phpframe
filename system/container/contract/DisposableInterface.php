<?php

declare(strict_types=1);

namespace System\Container\Contract;

/**
 * Scope kapanırken temizlik gerektiren servisler.
 *
 * Scope, örnekleri TERS oluşturma sırasıyla kapatır: son oluşan ilk kapanır,
 * böylece bir servis kendisinden önce oluşmuş bağımlılıklarını dispose
 * sırasında hâlâ kullanabilir.
 */
interface DisposableInterface
{
    /**
     * Kaynakları serbest bırakır. İstisna FIRLATMAMALI — scope teardown
     * sırasında fırlatılan istisna diğer servislerin dispose edilmesini
     * engellerdi (Scope yine de her çağrıyı try/catch ile sarar).
     */
    public function dispose(): void;
}
