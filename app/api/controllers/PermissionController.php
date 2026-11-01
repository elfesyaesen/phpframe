<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Services\PermissionService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

class PermissionController extends BaseController
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly Validator $Validator,
    ) {
    }

    public function index(): void
    {
        $this->response()->jsonResponse(Status::OK, ['data' => $this->permissions->all()]);
        return;
    }

    public function store(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'name'        => 'required|string|max:100|unique:permission,name',
            'description' => 'nullable|string|max:255',
        ]);

        $uuid = $this->permissions->create(
            $validated['name'],
            $validated['description'] ?? null
        );

        $this->response()->jsonResponse(Status::CREATED, ['uuid' => $uuid]);
        return;
    }

    public function destroy(string $uuid): void
    {
        $this->permissions->delete($uuid);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('permission.deleted')]
        );
        return;
    }

    public function setUserOverride(string $user_uuid): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'permission' => 'required|string',
            'effect'     => 'nullable|in:grant,deny',
        ]);

        $this->permissions->setUserOverride(
            $user_uuid,
            $validated['permission'],
            $validated['effect'] ?? null
        );

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('permission.override_updated')]
        );
        return;
    }
}
