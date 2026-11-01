<?php

declare(strict_types=1);

namespace System\Middleware\Interface;

use Closure;
use System\Http\Request;
use System\Http\Response;

interface MiddlewareInterface
{
    /**
     * @param Request $request
     * @param Response $response
     * @param Closure(Request): void $next
     * @param string|null $parameter
     */
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void;
}
