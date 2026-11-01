<?php

declare(strict_types=1);

namespace System\Container\Lifetime;

use System\Container\Contract\ContainerInterface;
use System\Container\Contract\ScopeInterface;
use System\Container\Core\Scope;

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

    public function disposeAll(): void
    {
        while ($this->stack !== []) {
            $scope = end($this->stack);
            if ($scope === false) {
                break;
            }

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
