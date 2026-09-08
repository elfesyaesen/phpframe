<?php

declare(strict_types=1);

namespace Api\Middleware;

use Api\Security\Gate;
use Closure;
use System\Http\Request;
use System\Http\Response;
use System\Middleware\Interface\MiddlewareInterface;
use System\Exceptions\ForbiddenException;

/**
 * API Role Middleware
 *
 * Kullanıcının belirli bir role sahip olup olmadığını kontrol eder.
 * Kullanım: 'api_role:administrator' veya 'api_role:administrator|user' (OR).
 *
 * Karar Gate'e delege edilir; Gate aktif kullanıcının rolünü DB'den çözer.
 * Route tanımında 'api_auth' MUTLAKA 'api_role'den ÖNCE gelmeli (userUuid'i yazar).
 * Rol eşleşmezse fail-closed (erişim reddedilir).
 *
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
