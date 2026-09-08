<?php

declare(strict_types=1);

namespace System\Event;

use System\Event\Contracts\ListenerInterface;
use Psr\Container\ContainerInterface;

/**
 * Event Dispatcher
 *
 * Modüller arası gevşek bağlantı için event sistemi.
 * Wildcard pattern, öncelik sırası ve sınıf tabanlı listener desteği.
 */
final class EventDispatcher
{
    /**
     * @var array<string, array<int, array{callback: callable|string, priority: int, once: bool}>>
     */
    private array $listeners = [];

    /**
     * @var array<string, bool> Bir kez çalışan listener'ların çalışıp çalışmadığı
     */
    private array $firedOnce = [];

    /**
     * @var array<string, bool> isWildcardCallback reflection sonucu cache'i
     * (her dispatch'te yeniden reflection yapılmasını önler).
     */
    private array $wildcardCache = [];

    public function __construct(
        private readonly ?ContainerInterface $container = null
    ) {}

    /**
     * Event listener kaydet
     *
     * @param string $event Event adı (wildcard destekler: user.*, *.created)
     * @param callable|string $callback Callback veya sınıf adı
     * @param int $priority Öncelik (yüksek = önce çalışır)
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

    /**
     * Bir kez çalışacak listener kaydet
     */
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
     * Event tetikle
     *
     * @param string $event Event adı
     * @param array $payload Event verileri
     * @return bool Tüm listener'lar başarılı çalıştı mı
     */
    public function dispatch(string $event, array $payload = []): bool
    {
        $listeners = $this->getListenersForEvent($event);

        // Önceliğe göre sırala (yüksek öncelik önce)
        usort($listeners, fn($a, $b) => $b['priority'] <=> $a['priority']);

        foreach ($listeners as $listener) {
            // Once listener kontrolü — key kayıt PATTERN'ine dayanır (dispatch edilen
            // concrete event'e değil); böylece 'user.*' once listener'ı user.created
            // ve user.deleted'da ayrı ayrı değil, toplamda BİR kez çalışır.
            $key = $this->getListenerKey($listener);
            if ($listener['once'] && isset($this->firedOnce[$key])) {
                continue;
            }

            $result = $this->callListener($listener['callback'], $event, $payload);

            // Once listener'ı işaretle
            if ($listener['once']) {
                $this->firedOnce[$key] = true;
            }

            // False döndürülürse zinciri durdur
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Belirli event için tüm listener'ları kaldır
     */
    public function forget(string $event): self
    {
        unset($this->listeners[$event]);
        return $this;
    }

    /**
     * Tüm listener'ları kaldır
     */
    public function flush(): self
    {
        $this->listeners = [];
        $this->firedOnce = [];
        return $this;
    }

    /**
     * Event için listener var mı?
     */
    public function hasListeners(string $event): bool
    {
        return !empty($this->getListenersForEvent($event));
    }

    /**
     * Event için listener sayısı
     */
    public function listenerCount(string $event): int
    {
        return count($this->getListenersForEvent($event));
    }

    /**
     * Tüm kayıtlı event'leri getir
     */
    public function getEvents(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * Event için listener'ları getir (wildcard dahil)
     */
    private function getListenersForEvent(string $event): array
    {
        $listeners = [];

        foreach ($this->listeners as $pattern => $eventListeners) {
            if ($this->matchesPattern($pattern, $event)) {
                // Kayıt pattern'ini taşı (once-key pattern bazlı üretilir).
                foreach ($eventListeners as $listener) {
                    $listener['pattern'] = $pattern;
                    $listeners[] = $listener;
                }
            }
        }

        return $listeners;
    }

    /**
     * Pattern eşleşmesi kontrol et
     */
    private function matchesPattern(string $pattern, string $event): bool
    {
        // Birebir eşleşme
        if ($pattern === $event) {
            return true;
        }

        // Wildcard kontrolü
        if (!str_contains($pattern, '*')) {
            return false;
        }

        // Pattern'i regex'e çevir
        $regex = str_replace(
            ['*', '.'],
            ['[^.]+', '\.'],
            $pattern
        );

        return (bool) preg_match("/^{$regex}$/", $event);
    }

    /**
     * Listener'ı çağır
     */
    private function callListener(callable|string $callback, string $event, array $payload): mixed
    {
        // String ise sınıf adı olarak değerlendir
        if (is_string($callback)) {
            $callback = $this->resolveListener($callback);
        }

        // Wildcard listener'a event adını da geç
        if ($this->isWildcardCallback($callback)) {
            return $callback($event, $payload);
        }

        return $callback($payload);
    }

    /**
     * Sınıf tabanlı listener'ı çözümle
     */
    private function resolveListener(string $className): callable
    {
        // Container varsa kullan
        if ($this->container !== null) {
            $instance = $this->container->get($className);
        } else {
            $instance = new $className();
        }

        // ListenerInterface implement ediyorsa handle metodunu kullan
        if ($instance instanceof ListenerInterface) {
            return [$instance, 'handle'];
        }

        // __invoke metodunu kullan
        if (method_exists($instance, '__invoke')) {
            return $instance;
        }

        throw new \RuntimeException(
            "Listener sınıfı ListenerInterface implement etmeli veya __invoke metodu olmalı: {$className}"
        );
    }

    /**
     * Wildcard callback kontrolü
     *
     * Reflection ile callback'in 2 parametre alıp almadığını kontrol et
     */
    private function isWildcardCallback(callable $callback): bool
    {
        // Reflection sonucu callback başına cache'lenir (dispatch hot-path'inde tekrar etmez).
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

            // İlk parametre string (event adı), ikinci array (payload)
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

    /**
     * Listener için benzersiz key oluştur
     */
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
