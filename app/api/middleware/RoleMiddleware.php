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
class RoleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Gate $gate)
    {
    }

    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        if ($parameter !== null && $parameter !== '') {
            $requiredRoles = explode('|', $parameter);

            if (!$this->gate->hasRole($requiredRoles)) {
                throw new ForbiddenException(
                    'Bu işlem için yetkiniz yok',
                    context: ['required_roles' => $requiredRoles, 'user_role' => $this->gate->role()]
                );
            }
        }

        $next($request);
    }
}
