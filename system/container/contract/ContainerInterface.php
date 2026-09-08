<?php

declare(strict_types=1);

namespace System\Container\Contract;

use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * PHPFrame runtime container'ı (DI-plan §23).
 *
 * PSR-11'i extend eder, yani `get()`/`has()` standarttır ve PSR uyumlu
 * herhangi bir kod bu container'ı kabul eder.
 *
 * DİKKAT — bilinçli spec sapması: DI-plan §23 `bind()`/`singleton()` gibi
 * mutator'ları da bu arayüze koyuyor. Mutator'ları RUNTIME arayüzüne koymak
 * §24'ü (production immutability) uygulanamaz kılar ve §37'nin yasakladığı
 * service locator kullanımını davet eder. Bu yüzden mutator'lar yalnızca
 * ContainerBuilderInterface'te yaşar; derlenmiş container'ın hiç mutator'ı
 * yoktur — immutability flag ile değil YAPISAL olarak sağlanır.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Yeni bir scope açar ve döndürür (HTTP isteği / CLI komutu).
     *
     * Scope'lar yığındır: iç içe scope desteklenir (CLI worker döngüsü,
     * sub-request). Açık scope varken yeni scope açmak öncekini kapatmaz.
     */
    public function createScope(?string $name = null): ScopeInterface;

    /** Şu an aktif olan scope, yoksa null. */
    public function scope(): ?ScopeInterface;

    /** Derlenmiş container mı (production) yoksa reflection destekli mi (dev)? */
    public function isCompiled(): bool;

    /**
     * Derlemenin kimliği. Derlenmiş container'da build hash'i, dev'de null.
     * Loglara ve `container:debug` çıktısına girer.
     */
    public function hash(): ?string;
}
