<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Config\CacheConfig;
use System\Config\RateLimitConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Middleware\MiddlewareRegistry;
use System\Middleware\RateLimitMiddleware;
use System\Security\RateLimiter;

final class SecurityProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->factory(
            RateLimiter::class,
            [self::class, 'rateLimiter'],
            Lifetime::SINGLETON,
            dependsOn: [AppConfig::class, CacheConfig::class],
        );

        $builder->factory(
            RateLimitMiddleware::class,
            [self::class, 'rateLimitMiddleware'],
            Lifetime::TRANSIENT,
            dependsOn: [RateLimiter::class, RateLimitConfig::class],
        );

        $builder->factory(
            MiddlewareRegistry::class,
            [self::class, 'middlewareRegistry'],
            Lifetime::SINGLETON,
            dependsOn: [AppConfig::class],
        );
    }

    public function provides(): array
    {
        return [
            RateLimiter::class,
            RateLimitMiddleware::class,
            MiddlewareRegistry::class,
        ];
    }

    public static function rateLimiter(PsrContainerInterface $container): RateLimiter
    {
        return new RateLimiter(
            cacheDir: $container->get(AppConfig::class)->cachePath('rate'),
            cache: $container->get(CacheConfig::class),
        );
    }

    public static function rateLimitMiddleware(PsrContainerInterface $container): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            limiter: $container->get(RateLimiter::class),
            defaults: $container->get(RateLimitConfig::class),
        );
    }

    public static function middlewareRegistry(PsrContainerInterface $container): MiddlewareRegistry
    {
        /** @var array<string, class-string> $map */
        $map = require $container->get(AppConfig::class)->path('config', 'middleware.php');

        return new MiddlewareRegistry($map, $container);
    }
}
