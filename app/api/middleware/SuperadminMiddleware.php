<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\Security\Gate;
use Closure;
use System\Http\Request;
use System\Http\Response;
use System\Middleware\Interface\MiddlewareInterface;
use System\Exceptions\ForbiddenException;

/**
 * @throws ForbiddenException
 */
class SuperadminMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Gate $gate)
    {
    }

    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        if (!$this->gate->isSuperadmin()) {
            throw new ForbiddenException(
                'Bu işlem için yetkiniz yok',
                context: ['required' => 'superadmin', 'user_role' => $this->gate->role()]
            );
        }

        $next($request);
    }
}
