<?php

declare(strict_types=1);

namespace Api\Security;

use System\Security\Contract\GateInterface;

use Api\Models\PermissionModel;
use Api\Models\RoleModel;
use System\Exceptions\ForbiddenException;
use System\Http\Request;

/**
 * Yetkilendirme karar noktası — tüm yetki kontrolleri buradan geçer.
 *
 * Model (basit):
 *   - Kullanıcının TEK rolü vardır (user_role).
 *   - is_superadmin rolü (administrator) HER yetkiyi otomatik geçer; sonradan
 *     eklenen yetkiler dahil. Bu yüzden administrator'a tek tek yetki atamak gerekmez.
 *   - Diğer roller: etkin yetki = rolün yetkileri ∪ kullanıcı grant(+) − kullanıcı deny(−).
 *   - Eşleştirme TAM AD ile yapılır (joker/wildcard yok). deny her zaman kazanır.
 *
 * Roller ve yetkiler veridir; çalışma zamanında eklenip çıkarılabilir (kod kataloğu yok).
 * Aktif kullanıcı Request'ten okunur (AuthMiddleware userUuid yazar).
 *
 * Uygulama (Api) katmanında yaşar: framework (System) çekirdeğine değil, uygulamanın
 * model'lerine bağımlı olduğundan bağımlılık yönü doğru (app → app) korunur.
 */
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
     * Yetkilerden EN AZ BİRİ varsa true (OR).
     *
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
     * Yetkilerin TAMAMI varsa true (AND).
     *
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
     * İzin yoksa ForbiddenException fırlatır.
     *
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

    /**
     * Aktif kullanıcının rol adı (yoksa null).
     */
    public function role(): ?string
    {
        $userUuid = $this->request->userUuid();

        return $userUuid !== null ? $this->context($userUuid)['role'] : null;
    }

    /**
     * Aktif kullanıcı verilen rol(ler)den birine sahip mi?
     *
     * @param string|list<string> $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $role = $this->role();

        return $role !== null && in_array($role, (array) $roles, true);
    }

    /**
     * Kullanıcının yetki bağlamını çözer (request başına bir kez; memoize).
     *
     * @return array{role: ?string, superadmin: bool, allowed: array<string, true>, denied: array<string, true>}
     */
    private function context(string $userUuid): array
    {
        if (isset($this->memo[$userUuid])) {
            return $this->memo[$userUuid];
        }

        $role       = $this->roles->findUserRole($userUuid);
        $superadmin = $role !== null && (bool) $role['is_superadmin'];

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
