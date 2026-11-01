<?php

declare(strict_types=1);

namespace System\Middleware;

use Closure;
use System\Http\Request;
use System\Http\Response;

final class Pipeline
{
    public function __construct(
        private readonly MiddlewareRegistry $registry,
    ) {}

    /**
     * @param array<string> $middleware
     * @param Closure(Request): void $destination
     */
    public function run(array $middleware, Request $request, Response $response, Closure $destination): void
    {
        $pipeline = array_reduce(
            array_reverse($middleware),
            fn (Closure $next, string $definition): Closure =>
                function (Request $request) use ($next, $response, $definition): void {
                    [$alias, $parameter] = MiddlewareRegistry::parse($definition);

                    $this->registry->resolve($alias)
                        ->handle($request, $response, $next, $parameter);
                },
            $destination,
        );

        $pipeline($request);
    }
}
