<?php

declare(strict_types=1);

namespace App\Api\Middleware;

use App\Api\Models\AuthModel;
use App\Api\Services\AuthService;
use Closure;
use System\Http\Request;
use System\Exceptions\UnauthorizedException;
use System\Http\Response;
use System\Middleware\Interface\MiddlewareInterface;
use System\Translation\Contract\TranslatorInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthModel $authModel,
        private readonly TranslatorInterface $translator,
    ) {}

    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        $payload = $this->auth->validate(AuthService::TOKEN_TYPE_ACCESS);
        $userUuid = $payload['user']['uuid'] ?? null;

        if ($userUuid === null) {
            $this->unauthorized();
        }

        $token = $this->auth->bearer();
        if ($token === false) {
            $this->unauthorized();
        }

        if (!$this->authModel->validAccessToken($userUuid, AuthService::hashToken($token))) {
            $this->unauthorized();
        }

        $user = $this->authModel->getUserByUuid($userUuid);
        if ($user === false) {
            $this->unauthorized();
        }

        $request->setUserUuid($userUuid);
        $request->setAuthUser($user);

        $next($request);
    }

    private function unauthorized(): never
    {
        throw new UnauthorizedException($this->translator->trans('auth.required'));
    }
}
