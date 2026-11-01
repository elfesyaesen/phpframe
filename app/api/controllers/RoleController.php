<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\Services\RoleService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

class RoleController extends BaseController
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly Validator $Validator,
    ) {
    }

    public function index(): void
    {
        $this->response()->jsonResponse(Status::OK, ['data' => $this->roles->all()]);
        return;
    }

    public function store(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'name' => 'required|string|max:100|unique:role,name',
        ]);

        $uuid = $this->roles->create($validated['name']);

        $this->response()->jsonResponse(Status::CREATED, ['uuid' => $uuid]);
        return;
    }

    public function update(string $uuid): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'name' => 'required|string|max:100',
        ]);

        $this->roles->rename($uuid, $validated['name']);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('role.updated')]
        );
        return;
    }

    public function destroy(string $uuid): void
    {
        $this->roles->delete($uuid);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('role.deleted')]
        );
        return;
    }

    public function syncPermissions(string $uuid): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'permissions'   => 'nullable|array',
            'permissions.*' => 'uuid',
        ]);

        $this->roles->syncPermissions($uuid, $validated['permissions'] ?? []);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('role.permissions_updated')]
        );
        return;
    }

    public function assignToUser(string $user_uuid): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'role' => 'required|string',
        ]);

        $this->roles->assignToUser($user_uuid, $validated['role']);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('role.assigned')]
        );
        return;
    }
}
