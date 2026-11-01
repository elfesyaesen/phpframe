<?php

declare(strict_types=1);

namespace App\Api\Security;

use System\Security\Contract\GateInterface;

use App\Api\Models\PermissionModel;
use App\Api\Models\RoleModel;
use System\Exceptions\ForbiddenException;
use System\Http\Request;

final class Gate implements GateInterface
{
    /** @var array<string, array{role: ?string, superadmin: bool, allowed: array<string, true>, denied: array<string, true>}> */
    private array $memo = [];

    public function __construct(
        private readonly Request $request,
        private readonly RoleModel $roles,
        private readonly PermissionModel $permissions,
    ) {
    }

    public function allows(string $permission): bool
    {
        $userUuid = $this->request->userUuid();
        if ($userUuid === null) {
            return false;
        }

        $ctx = $this->context($userUuid);

        if ($ctx['superadmin']) {
            return true;
        }
        if (isset($ctx['denied'][$permission])) {
            return false;
        }

        return isset($ctx['allowed'][$permission]);
    }

    public function denies(string $permission): bool
    {
        return !$this->allows($permission);
    }

    /**
     * @param iterable<string> $permissions
     */
    public function any(iterable $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<string> $permissions
     */
    public function all(iterable $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws ForbiddenException
     */
    public function authorize(string $permission): void
    {
        if (!$this->allows($permission)) {
            throw new ForbiddenException(
                'Bu işlem için yetkiniz yok',
                context: ['permission' => $permission]
            );
        }
    }

    public function isSuperadmin(): bool
    {
        $userUuid = $this->request->userUuid();

        return $userUuid !== null && $this->context($userUuid)['superadmin'];
    }

    public function role(): ?string
    {
        $userUuid = $this->request->userUuid();

        return $userUuid !== null ? $this->context($userUuid)['role'] : null;
    }

    /**
     * @param string|list<string> $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $role = $this->role();

        return $role !== null && in_array($role, (array) $roles, true);
    }

    /**
     * @return array{role: ?string, superadmin: bool, allowed: array<string, true>, denied: array<string, true>}
     */
    private function context(string $userUuid): array
    {
        if (isset($this->memo[$userUuid])) {
            return $this->memo[$userUuid];
        }

        $role       = $this->roles->findUserRole($userUuid);
        $superadmin = $role !== null && (bool) $role['superadmin'];

        $allowed = [];
        if ($role !== null && !$superadmin) {
            foreach ($this->roles->permissionNamesForRole($role['uuid']) as $name) {
                $allowed[$name] = true;
            }
        }

        $overrides = $this->permissions->userOverrides($userUuid);
        foreach ($overrides['grant'] as $name) {
            $allowed[$name] = true;
        }

        $denied = [];
        foreach ($overrides['deny'] as $name) {
            $denied[$name] = true;
        }

        return $this->memo[$userUuid] = [
            'role'       => $role['name'] ?? null,
            'superadmin' => $superadmin,
            'allowed'    => $allowed,
            'denied'     => $denied,
        ];
    }
}
