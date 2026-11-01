<?php

declare(strict_types=1);

namespace System\Event;

final class Event
{
    private static ?EventDispatcher $dispatcher = null;

    public static function setDispatcher(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    public static function getDispatcher(): EventDispatcher
    {
        if (self::$dispatcher === null) {
            self::$dispatcher = new EventDispatcher();
        }

        return self::$dispatcher;
    }

    public static function listen(string $event, callable|string $callback, int $priority = 0): void
    {
        self::getDispatcher()->listen($event, $callback, $priority);
    }

    public static function once(string $event, callable|string $callback, int $priority = 0): void
    {
        self::getDispatcher()->once($event, $callback, $priority);
    }

    public static function dispatch(string $event, array $payload = []): bool
    {
        return self::getDispatcher()->dispatch($event, $payload);
    }

    public static function forget(string $event): void
    {
        self::getDispatcher()->forget($event);
    }

    public static function flush(): void
    {
        self::getDispatcher()->flush();
    }

    public static function hasListeners(string $event): bool
    {
        return self::getDispatcher()->hasListeners($event);
    }

    public static function listenerCount(string $event): int
    {
        return self::getDispatcher()->listenerCount($event);
    }

    public static function getEvents(): array
    {
        return self::getDispatcher()->getEvents();
    }
}
