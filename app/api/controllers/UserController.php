<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Services\AvatarService;
use App\Api\Services\UserService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

class UserController extends BaseController
{
    public function __construct(
        private readonly UserService $Users,
        private readonly AvatarService $Avatar,
        private readonly Validator $Validator,
    ) {
    }

    public function register(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'username'              => 'required|string|alpha_num|min:3|max:50|unique_active:user,username',
            'email'                 => 'required|email|unique_active:user,email',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
            'firstname'             => 'nullable|string|max:100',
            'lastname'              => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:20',
        ]);

        $bundle = $this->Users->register($validated);

        $this->response()->jsonResponse(Status::CREATED, $bundle->toArray());
        return;
    }

    public function resetPassword(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'email' => 'required|email',
        ]);

        $this->Users->requestPasswordReset($validated['email']);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_reset_sent')]
        );
        return;
    }

    public function confirmResetPassword(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'token'                 => 'required|string|regex:/^[a-f0-9]{64}$/',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->Users->confirmPasswordReset($validated['token'], $validated['password']);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_reset_done')]
        );
        return;
    }

    public function confirmResetPasswordWithCode(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'email'                 => 'required|email',
            'code'                  => 'required|string|regex:/^[0-9]{6}$/',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->Users->confirmPasswordResetWithCode(
            $validated['email'],
            $validated['code'],
            $validated['password']
        );

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_reset_done')]
        );
        return;
    }

    public function changePassword(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'current_password'      => 'required|string',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->Users->changePassword(
            (string) $this->request()->userUuid(),
            $validated['current_password'],
            $validated['password'],
        );

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_updated')]
        );
        return;
    }

    public function me(): void
    {
        $this->response()->jsonResponse(
            Status::OK,
            $this->Users->profile((string) $this->request()->userUuid())
        );
        return;
    }

    public function updateProfile(): void
    {
        $body = $this->request()->json();

        $validated = $this->Validator->validate($body, [
            'firstname' => 'nullable|string|max:100',
            'lastname'  => 'nullable|string|max:100',
            'phone'     => 'nullable|string|max:20',
        ]);

        $profile = $this->Users->updateProfile(
            (string) $this->request()->userUuid(),
            $body,
            $validated,
        );

        $this->response()->jsonResponse(Status::OK, $profile);
        return;
    }

    public function deleteAccount(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'current_password' => 'required|string',
        ]);

        $this->Users->deleteAccount(
            (string) $this->request()->userUuid(),
            $validated['current_password'],
        );

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.account_deleted')]
        );
        return;
    }

    public function uploadAvatar(): void
    {
        $this->Avatar->upload(
            (string) $this->request()->userUuid(),
            $this->request()->files('avatar')
        );

        $this->response()->jsonResponse(Status::CREATED, []);
        return;
    }

    public function deleteAvatar(): void
    {
        $this->Avatar->remove((string) $this->request()->userUuid());

        $this->response()->jsonResponse(Status::OK, []);
        return;
    }

    public function serveAvatar(): void
    {
        $this->Avatar->stream((string) $this->request()->userUuid());
        return;
    }
}
