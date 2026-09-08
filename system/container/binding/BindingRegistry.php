<?php

declare(strict_types=1);

namespace System\Container\Binding;

use System\Container\Exception\BindingException;

/**
 * Alias zincirlerini çözer (A → B → C ⇒ A çözümü C'dir).
 *
 * Zincir çözümü döngü korumalıdır: karşılıklı iki binding (A → B, B → A)
 * sonsuz döngü değil anlaşılır bir `ALIAS_CYCLE` hatası üretir.
 */
final class BindingRegistry
{
    /** @var array<string, Binding> */
    private array $bindings = [];

    public function set(Binding $binding): void
    {
        $this->bindings[$binding->id] = $binding;
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    public function get(string $id): ?Binding
    {
        return $this->bindings[$id] ?? null;
    }

    /**
     * Binding'i kaldırır.
     *
     * Dekoratör düzleştirmesi kullanır: dekore edilen arayüz artık somut
     * sınıfa değil EN DIŞ dekoratöre çözülmeli (bkz. DecoratorFlattener).
     */
    public function remove(string $id): void
    {
        unset($this->bindings[$id]);
    }

    /**
     * Zincirin sonundaki id'yi döndürür. Binding yoksa id'yi olduğu gibi verir.
     */
    public function resolve(string $id): string
    {
        if (!isset($this->bindings[$id])) {
            return $id;
        }

        $seen = [$id => true];
        $chain = [$id];
        $current = $id;

        while (isset($this->bindings[$current])) {
            $next = $this->bindings[$current]->target;

            // Kendine bind (A → A) meşrudur: "bu id'yi autowire et" demektir.
            if ($next === $current) {
                return $current;
            }

            if (isset($seen[$next])) {
                $chain[] = $next;
                throw BindingException::aliasCycle($chain);
            }

            $seen[$next] = true;
            $chain[] = $next;
            $current = $next;
        }

        return $current;
    }

    /**
     * Bir id'ye ulaşmak için izlenen tam zincir — `container:debug` çıktısında
     * "PaymentInterface → StripePayment" okunu göstermek için.
     *
     * @return list<string>
     */
    public function chain(string $id): array
    {
        $chain = [$id];
        $seen = [$id => true];
        $current = $id;

        while (isset($this->bindings[$current])) {
            $next = $this->bindings[$current]->target;
            if ($next === $current || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $chain[] = $next;
            $current = $next;
        }

        return $chain;
    }

    /**
     * @return array<string, Binding>
     */
    public function all(): array
    {
        return $this->bindings;
    }

    /**
     * @return array<string, Binding>
     */
    public function sorted(): array
    {
        $sorted = $this->bindings;
        ksort($sorted);

        return $sorted;
    }

    public function count(): int
    {
        return count($this->bindings);
    }
}
