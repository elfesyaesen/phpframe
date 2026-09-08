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
 * API Permission Middleware
 *
 * Kullanıcının belirli bir yetkiye sahip olup olmadığını kontrol eder.
 * Kullanım: 'api_permission:users.read' veya 'api_permission:users.read|users.update' (OR).
 *
 * Karar Gate'e delege edilir; Gate aktif kullanıcının etkin yetkilerini
 * (rol ∪ grant − deny, administrator → tümü) DB'den çözer. Route tanımında
 * 'api_auth' MUTLAKA 'api_permission'dan ÖNCE gelmeli. Yetki yoksa fail-closed.
 *
 * @throws ForbiddenException
 */
class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Gate $gate)
    {
    }

    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        if ($parameter !== null && $parameter !== '') {
            $requiredPermissions = explode('|', $parameter);

            // En az bir yetki yeterli (OR).
            if (!$this->gate->any($requiredPermissions)) {
                throw new ForbiddenException(
                    'Bu işlem için yetkiniz yok',
                    context: ['required_permissions' => $requiredPermissions]
                );
            }
        }

        $next($request);
    }
}
