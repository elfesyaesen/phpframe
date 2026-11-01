<?php

declare(strict_types=1);

namespace App\Api\Services;

use App\Api\Exceptions\DuplicateNameException;
use App\Api\Models\PermissionModel;
use System\Exceptions\NotFoundException;
use System\Exceptions\ValidationException;
use System\Translation\Contract\TranslatorInterface;

final class PermissionService
{
    public function __construct(
        private readonly PermissionModel $permissions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->permissions->all();
    }

    /**
     * @throws ValidationException
     */
    public function create(string $name, ?string $description): string
    {
        try {
            return $this->permissions->createPermission($name, $description);
        } catch (DuplicateNameException $e) {
            throw new ValidationException(
                ['name' => [$this->translator->trans('permission.name_taken')]],
                $this->translator->trans('permission.name_taken'),
                previous: $e,
            );
        }
    }

    /**
     * @throws NotFoundException
     */
    public function delete(string $uuid): void
    {
        if (!$this->permissions->deletePermission($uuid)) {
            throw new NotFoundException($this->translator->trans('permission.not_found'));
        }
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function setUserOverride(string $userUuid, string $permissionName, ?string $effect): void
    {
        $permission = $this->permissions->findByName($permissionName);

        if ($permission === null) {
            throw new NotFoundException($this->translator->trans('permission.not_found'));
        }

        if (!$this->permissions->setUserOverride($userUuid, (string) $permission['uuid'], $effect)) {
            throw new ValidationException(
                ['user_uuid' => [$this->translator->trans('permission.override_failed')]],
                $this->translator->trans('permission.override_failed'),
            );
        }
    }
}
