<?php

declare(strict_types=1);

namespace System\Event;

/**
 * Event Facade
 *
 * EventDispatcher için statik erişim sağlar.
 *
 * Örnek:
 *   Event::listen('user.created', fn($user) => sendEmail($user));
 *   Event::dispatch('user.created', ['id' => 1, 'email' => 'test@test.com']);
 */
final class Event
{
    private static ?EventDispatcher $dispatcher = null;

    /**
     * Dispatcher'ı ayarla
     */
    public static function setDispatcher(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    /**
     * Dispatcher'ı al
     */
    public static function getDispatcher(): EventDispatcher
    {
        if (self::$dispatcher === null) {
            self::$dispatcher = new EventDispatcher();
        }

        return self::$dispatcher;
    }

    /**
     * Event listener kaydet
     */
    public static function listen(string $event, callable|string $callback, int $priority = 0): void
    {
        self::getDispatcher()->listen($event, $callback, $priority);
    }

    /**
     * Bir kez çalışacak listener kaydet
     */
    public static function once(string $event, callable|string $callback, int $priority = 0): void
    {
        self::getDispatcher()->once($event, $callback, $priority);
    }

    /**
     * Event tetikle
     */
    public static function dispatch(string $event, array $payload = []): bool
    {
        return self::getDispatcher()->dispatch($event, $payload);
    }

    /**
     * Belirli event için listener'ları kaldır
     */
    public static function forget(string $event): void
    {
        self::getDispatcher()->forget($event);
    }

    /**
     * Tüm listener'ları kaldır
     */
    public static function flush(): void
    {
        self::getDispatcher()->flush();
    }

    /**
     * Event için listener var mı?
     */
    public static function hasListeners(string $event): bool
    {
        return self::getDispatcher()->hasListeners($event);
    }

    /**
     * Event için listener sayısı
     */
    public static function listenerCount(string $event): int
    {
        return self::getDispatcher()->listenerCount($event);
    }

    /**
     * Tüm kayıtlı event'leri getir
     */
    public static function getEvents(): array
    {
        return self::getDispatcher()->getEvents();
    }
}
