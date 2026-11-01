<?php

declare(strict_types=1);

namespace System\Event;

use System\Event\Contracts\ListenerInterface;
use Psr\Container\ContainerInterface;

final class EventDispatcher
{
    /**
     * @var array<string, array<int, array{callback: callable|string, priority: int, once: bool}>>
     */
    private array $listeners = [];

    /**
     * @var array<string, bool>
     */
    private array $firedOnce = [];

    /**
     * @var array<string, bool>
     */
    private array $wildcardCache = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null
    ) {}

    /**
     * @param string $event
     * @param callable|string $callback
     * @param int $priority
     */
    public function listen(string $event, callable|string $callback, int $priority = 0): self
    {
        $this->listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority,
            'once' => false,
        ];

        return $this;
    }

    public function once(string $event, callable|string $callback, int $priority = 0): self
    {
        $this->listeners[$event][] = [
            'callback' => $callback,
            'priority' => $priority,
            'once' => true,
        ];

        return $this;
    }

    /**
     * @param string $event
     * @param array $payload
     * @return bool
     */
    public function dispatch(string $event, array $payload = []): bool
    {
        $listeners = $this->getListenersForEvent($event);

        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        foreach ($listeners as $listener) {
            $key = $this->getListenerKey($listener);
            if ($listener['once'] && isset($this->firedOnce[$key])) {
                continue;
            }

            $result = $this->callListener($listener['callback'], $event, $payload);

            if ($listener['once']) {
                $this->firedOnce[$key] = true;
            }

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    public function forget(string $event): self
    {
        unset($this->listeners[$event]);
        return $this;
    }

    public function flush(): self
    {
        $this->listeners = [];
        $this->firedOnce = [];
        return $this;
    }

    public function hasListeners(string $event): bool
    {
        return !empty($this->getListenersForEvent($event));
    }

    public function listenerCount(string $event): int
    {
        return count($this->getListenersForEvent($event));
    }

    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }

    private function getListenersForEvent(string $event): array
    {
        $listeners = [];

        foreach ($this->listeners as $pattern => $eventListeners) {
            if ($this->matchesPattern($pattern, $event)) {
                foreach ($eventListeners as $listener) {
                    $listener['pattern'] = $pattern;
                    $listeners[] = $listener;
                }
            }
        }

        return $listeners;
    }

    private function matchesPattern(string $pattern, string $event): bool
    {
        if ($pattern === $event) {
            return true;
        }

        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = str_replace(
            ['*', '.'],
            ['[^.]+', '\.'],
            $pattern
        );

        return (bool) preg_match("/^{$regex}$/", $event);
    }

    private function callListener(callable|string $callback, string $event, array $payload): mixed
    {
        if (is_string($callback)) {
            $callback = $this->resolveListener($callback);
        }

        if ($this->isWildcardCallback($callback)) {
            return $callback($event, $payload);
        }

        return $callback($payload);
    }

    private function resolveListener(string $className): callable
    {
        if ($this->container !== null) {
            $instance = $this->container->get($className);
        } else {
            $instance = new $className();
        }

        if ($instance instanceof ListenerInterface) {
            return [$instance, 'handle'];
        }

        if (method_exists($instance, '__invoke')) {
            return $instance;
        }

        throw new \RuntimeException(
            "Listener sınıfı ListenerInterface implement etmeli veya __invoke metodu olmalı: {$className}"
        );
    }

    private function isWildcardCallback(callable $callback): bool
    {
        $cacheKey = match (true) {
            is_array($callback)            => (is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]) . '::' . $callback[1],
            $callback instanceof \Closure  => 'closure:' . spl_object_id($callback),
            default                        => null,
        };
        if ($cacheKey !== null && isset($this->wildcardCache[$cacheKey])) {
            return $this->wildcardCache[$cacheKey];
        }

        $result = false;
        try {
            if (is_array($callback)) {
                $reflection = new \ReflectionMethod($callback[0], $callback[1]);
            } elseif ($callback instanceof \Closure) {
                $reflection = new \ReflectionFunction($callback);
            } else {
                return false;
            }

            $params = $reflection->getParameters();

            if (count($params) >= 2) {
                $firstType = $params[0]->getType();
                $result = $firstType instanceof \ReflectionNamedType
                    && $firstType->getName() === 'string';
            }
        } catch (\ReflectionException) {
            $result = false;
        }

        if ($cacheKey !== null) {
            $this->wildcardCache[$cacheKey] = $result;
        }

        return $result;
    }

    private function getListenerKey(array $listener): string
    {
        $scope = $listener['pattern'] ?? '';
        $callback = $listener['callback'];

        if (is_string($callback)) {
            return "{$scope}:{$callback}";
        }

        if (is_array($callback)) {
            $class = is_object($callback[0]) ? $callback[0]::class : $callback[0];
            return "{$scope}:{$class}::{$callback[1]}";
        }

        return "{$scope}:" . spl_object_hash($callback);
    }
}
