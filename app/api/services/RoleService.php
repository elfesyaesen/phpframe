<?php

declare(strict_types=1);

namespace App\Api\Services;

use App\Api\Exceptions\DuplicateNameException;
use App\Api\Exceptions\RecordInUseException;
use App\Api\Models\RoleModel;
use System\Database\Database;
use System\Exceptions\ConflictException;
use System\Exceptions\NotFoundException;
use System\Exceptions\ValidationException;
use System\Translation\Contract\TranslatorInterface;

final class RoleService
{
    public function __construct(
        private readonly RoleModel $roles,
        private readonly Database $database,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->roles->all();
    }

    /**
     * @throws ValidationException
     */
    public function create(string $name): string
    {
        try {
            return $this->roles->createRole($name);
        } catch (DuplicateNameException $e) {
            throw $this->nameTaken($e);
        }
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function rename(string $uuid, string $name): void
    {
        $this->requireRole($uuid);

        try {
            $this->roles->renameRole($uuid, $name);
        } catch (DuplicateNameException $e) {
            throw $this->nameTaken($e);
        }
    }

    /**
     * @throws NotFoundException
     * @throws ConflictException
     */
    public function delete(string $uuid): void
    {
        if ((bool) ($this->roles->findByUuid($uuid)['superadmin'] ?? false)) {
            throw new ConflictException($this->translator->trans('role.superadmin_protected'));
        }

        try {
            $deleted = $this->roles->deleteRole($uuid);
        } catch (RecordInUseException $e) {
            throw new ConflictException($this->translator->trans('role.in_use'), previous: $e);
        }

        if (!$deleted) {
            throw new NotFoundException($this->translator->trans('role.not_found'));
        }
    }

    /**
     * @param list<string> $permissionUuids
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function syncPermissions(string $roleUuid, array $permissionUuids): void
    {
        $this->requireRole($roleUuid);

        try {
            $this->database->transaction(function () use ($roleUuid, $permissionUuids): void {
                $this->roles->clearPermissions($roleUuid);
                $this->roles->addPermissions($roleUuid, $permissionUuids);
            });
        } catch (RecordInUseException $e) {
            throw new ValidationException(
                ['permissions' => [$this->translator->trans('permission.invalid')]],
                $this->translator->trans('permission.invalid'),
                previous: $e,
            );
        }
    }

    /**
     * @throws NotFoundException
     * @throws ConflictException
     * @throws ValidationException
     */
    public function assignToUser(string $userUuid, string $roleName): void
    {
        $role = $this->roles->findByName($roleName);

        if ($role === null) {
            throw new NotFoundException($this->translator->trans('role.not_found'));
        }

        $assigned = $this->database->transaction(function () use ($userUuid, $role): bool {
            $current = $this->roles->findUserRole($userUuid);
            $demotes = (bool) ($current['superadmin'] ?? false) && !(bool) $role['superadmin'];

            if ($demotes && $this->roles->countSuperadminUsers() <= 1) {
                throw new ConflictException($this->translator->trans('role.last_superadmin'));
            }

            return $this->roles->assignUserRole($userUuid, (string) $role['uuid']);
        });

        if (!$assigned) {
            throw new ValidationException(
                ['user_uuid' => [$this->translator->trans('role.assign_failed')]],
                $this->translator->trans('role.assign_failed'),
            );
        }
    }

    /**
     * @throws NotFoundException
     */
    private function requireRole(string $uuid): void
    {
        if ($this->roles->findByUuid($uuid) === null) {
            throw new NotFoundException($this->translator->trans('role.not_found'));
        }
    }

    private function nameTaken(DuplicateNameException $previous): ValidationException
    {
        $message = $this->translator->trans('role.name_taken');

        return new ValidationException(['name' => [$message]], $message, previous: $previous);
    }
}
