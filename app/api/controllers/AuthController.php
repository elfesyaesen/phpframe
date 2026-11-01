<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Services\AuthService;
use App\Api\Services\UserService;
use System\Engine\BaseController;
use System\Helpers\Status;

class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly UserService $users,
    ) {
    }

    public function login(): void
    {
        $body = $this->request()->json();

        $bundle = $this->auth->login(
            (string) ($body['email'] ?? ''),
            (string) ($body['password'] ?? ''),
        );

        $this->response()->jsonResponse(Status::OK, $bundle->toArray());
        return;
    }

    public function refresh(): void
    {
        $bundle = $this->auth->refresh();

        $this->response()->jsonResponse(Status::OK, $bundle->toArray());
        return;
    }

    public function logout(): void
    {
        $this->auth->logout((string) $this->request()->userUuid());

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('auth.logout_success')]
        );
        return;
    }

    public function me(): void
    {
        $this->response()->jsonResponse(
            Status::OK,
            $this->users->profile((string) $this->request()->userUuid())
        );
        return;
    }
}
