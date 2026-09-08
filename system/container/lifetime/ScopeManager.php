<?php

declare(strict_types=1);

namespace System\Container\Lifetime;

use System\Container\Contract\ContainerInterface;
use System\Container\Contract\ScopeInterface;
use System\Container\Core\Scope;

/**
 * Açık scope'ların YIĞINI.
 *
 * Neden tek slot değil yığın: bir CLI worker döngüsü ("kuyruktan iş al,
 * işle, scope'u kapat, tekrar") ve sub-request iç içe scope ister. Tek slot
 * bunları sessizce birbirine karıştırır.
 *
 * `active()` yığının tepesidir. Dispose yığının herhangi bir yerinden
 * çağrılabilir (release() sırayı korur), çünkü shutdown hook'ları scope'ları
 * açılış sırasıyla kapatmayabilir.
 */
final class ScopeManager
{
    /** @var list<ScopeInterface> */
    private array $stack = [];

    public function begin(string $name, ContainerInterface $container): ScopeInterface
    {
        $scope = new Scope($name, $container, $this);

        $this->stack[] = $scope;

        return $scope;
    }

    /** Yığının tepesindeki scope, yoksa null. */
    public function active(): ?ScopeInterface
    {
        $top = end($this->stack);

        return $top === false ? null : $top;
    }

    public function hasActive(): bool
    {
        return $this->stack !== [];
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    /**
     * Scope'u yığından çıkarır. Scope::dispose() tarafından çağrılır;
     * doğrudan çağrılmamalı.
     */
    public function release(ScopeInterface $scope): void
    {
        foreach ($this->stack as $index => $candidate) {
            if ($candidate === $scope) {
                unset($this->stack[$index]);
                $this->stack = array_values($this->stack);

                return;
            }
        }
    }

    /**
     * Tüm açık scope'ları tepeden aşağı kapatır.
     *
     * Beklenmedik bir çıkışta (shutdown) kalan scope'ları temizlemek için.
     * `dispose()` idempotent ve `release()` çağırdığı için döngü yığın
     * boşalana kadar sürer.
     */
    public function disposeAll(): void
    {
        while ($this->stack !== []) {
            $scope = end($this->stack);
            if ($scope === false) {
                break;
            }

            // Güvenlik: dispose release etmezse sonsuz döngüye girmeyelim.
            $before = count($this->stack);
            $scope->dispose();

            if (count($this->stack) >= $before) {
                array_pop($this->stack);
            }
        }
    }

    /**
     * @return list<ScopeInterface>
     */
    public function all(): array
    {
        return $this->stack;
    }
}
