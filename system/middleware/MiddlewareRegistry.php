<?php

declare(strict_types=1);

namespace System\Middleware;

use Psr\Container\ContainerInterface;
use System\Container\Contract\ContainerInterface as FrameContainerInterface;
use System\Exceptions\InternalServerException;
use System\Middleware\Interface\MiddlewareInterface;

final class MiddlewareRegistry
{
    /**
     * @param array<string, class-string<MiddlewareInterface>> $map
     */
    public function __construct(
        private readonly array $map,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @return array{0: string, 1: string|null}
     */
    public static function parse(string $definition): array
    {
        /** @var array{0: string, 1: string|null} $parts */
        $parts = array_pad(explode(':', $definition, 2), 2, null);

        return $parts;
    }

    public function has(string $alias): bool
    {
        return isset($this->map[$alias]);
    }

    public function resolve(string $alias): MiddlewareInterface
    {
        $class = $this->map[$alias] ?? null;

        if ($class === null) {
            throw new InternalServerException(
                message: "Tanımsız middleware alias'ı: '{$alias}'",
                context: [
                    'alias' => $alias,
                    'available' => array_keys($this->map),
                    'hint' => 'config/middleware.php dosyasına ekleyin.',
                ]
            );
        }

        $middleware = $this->container->get($class);

        if (!$middleware instanceof MiddlewareInterface) {
            throw new InternalServerException(
                message: "Middleware MiddlewareInterface uygulamıyor: '{$class}'",
                context: ['alias' => $alias, 'class' => $class]
            );
        }

        return $middleware;
    }

    /**
     * @return array<string, class-string<MiddlewareInterface>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
