<?php

declare(strict_types=1);

namespace System\Runtime;

use Closure;
use Throwable;

final class ShutdownRegistry
{
    /** @var array<int, list<Closure>> */
    private array $hooks = [];

    private bool $armed = false;
    private bool $ran = false;

    /**
     * @param int $priority
     * @param Closure(): void $hook
     */
    public function on(int $priority, Closure $hook): void
    {
        $this->hooks[$priority][] = $hook;

        $this->arm();
    }

    private function arm(): void
    {
        if ($this->armed) {
            return;
        }

        $this->armed = true;

        register_shutdown_function($this->run(...));
    }

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
                }
            }
        }
    }

    public function count(): int
    {
        return array_sum(array_map('count', $this->hooks));
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }
}
