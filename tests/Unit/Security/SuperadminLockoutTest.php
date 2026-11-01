<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Api\Models\RoleModel;
use App\Api\Services\RoleService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use System\Database\Database;
use System\Exceptions\ConflictException;
use System\Translation\Contract\TranslatorInterface;

#[CoversClass(RoleService::class)]
final class SuperadminLockoutTest extends TestCase
{
    private const SUPERADMIN = ['uuid' => 'r-admin', 'name' => 'administrator', 'superadmin' => 1];
    private const BASE       = ['uuid' => 'r-user', 'name' => 'user', 'superadmin' => 0];

    public function testLastSuperadminCannotBeDemoted(): void
    {
        $roles = $this->roles(currentRole: self::SUPERADMIN, superadminCount: 1);
        $roles->expects(self::never())->method('assignUserRole');

        $this->expectException(ConflictException::class);
        $this->service($roles)->assignToUser('u-1', 'user');
    }

    public function testSuperadminCanBeDemotedWhenAnotherRemains(): void
    {
        $roles = $this->roles(currentRole: self::SUPERADMIN, superadminCount: 2);
        $roles->expects(self::once())->method('assignUserRole')->with('u-1', 'r-user')->willReturn(true);

        $this->service($roles)->assignToUser('u-1', 'user');
    }

    public function testSuperadminRoleCannotBeDeleted(): void
    {
        $roles = $this->createMock(RoleModel::class);
        $roles->method('findByUuid')->willReturn(self::SUPERADMIN);
        $roles->expects(self::never())->method('deleteRole');

        $this->expectException(ConflictException::class);
        $this->service($roles)->delete('r-admin');
    }

    /**
     * @param array{uuid: string, name: string, superadmin: int} $currentRole
     */
    private function roles(array $currentRole, int $superadminCount): RoleModel&\PHPUnit\Framework\MockObject\MockObject
    {
        $roles = $this->createMock(RoleModel::class);
        $roles->method('findByName')->willReturn(self::BASE);
        $roles->method('findUserRole')->willReturn($currentRole);
        $roles->method('countSuperadminUsers')->willReturn($superadminCount);

        return $roles;
    }

    private function service(RoleModel $roles): RoleService
    {
        $database = $this->createStub(Database::class);
        $database->method('transaction')->willReturnCallback(static fn (callable $fn): mixed => $fn());

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new RoleService($roles, $database, $translator);
    }
}
