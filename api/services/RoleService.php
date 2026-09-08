<?php

declare(strict_types=1);

namespace Api\Services;

use Api\Exceptions\DuplicateNameException;
use Api\Exceptions\RecordInUseException;
use Api\Models\RoleModel;
use System\Database\Database;
use System\Exceptions\ConflictException;
use System\Exceptions\NotFoundException;
use System\Exceptions\ValidationException;
use System\Translation\Contract\TranslatorInterface;

/**
 * Rol yönetimi kullanım senaryoları.
 *
 * Katman sözleşmesi için bkz. docs/api-layer.md ve `PermissionService`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * TRANSACTION SAHİPLİĞİ BURADA.
 *
 * `syncPermissions` eskiden `RoleModel` içinde kendi transaction'ını
 * açıyordu ve üç kusuru vardı — en ciddisi: yalnızca `PDOException`
 * yakalanıyordu, dolayısıyla başka bir `Throwable` transaction'ı AÇIK
 * bırakıyordu. `Database::getConnection()` PDO'yu memoize ettiği için, aynı
 * istekteki sonraki HER sorgu o yetim transaction'a katılıyordu; istek
 * bitince örtük rollback olduğundan, sonraki yazmalar da sessizce yok
 * oluyordu.
 *
 * Artık transaction `Database::transaction()` ile burada açılıyor: model
 * yalnızca iki saf yazma sunuyor (`clearPermissions`, `addPermissions`) ve
 * herhangi bir istisna — tipi ne olursa olsun — rollback tetikleyip yukarı
 * çıkıyor.
 * ─────────────────────────────────────────────────────────────────────────
 */
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
     * @throws ValidationException ad zaten kullanımdaysa
     */
    public function create(string $name): string
    {
        try {
            return $this->roles->createRole($name);
        } catch (DuplicateNameException $e) {
            // 422 korunuyor (409 değil) — gerekçe: PermissionService::create().
            throw $this->nameTaken($e);
        }
    }

    /**
     * Rolü yeniden adlandırır.
     *
     * @throws NotFoundException   rol yoksa
     * @throws ValidationException yeni ad başka bir rolde kullanımdaysa
     */
    public function rename(string $uuid, string $name): void
    {
        $this->requireRole($uuid);

        try {
            // Dönüş KASTEN yok sayılıyor: aynı adla çağrıldığında MySQL 0
            // satır bildirir ve `false` döner, ama istenen son durum zaten
            // sağlanmıştır. Mevcut davranış da böyleydi (controller dönüşü
            // hiç kontrol etmiyordu); yeniden adlandırma İDEMPOTENTTİR.
            $this->roles->renameRole($uuid, $name);
        } catch (DuplicateNameException $e) {
            throw $this->nameTaken($e);
        }
    }

    /**
     * @throws NotFoundException rol yoksa
     * @throws ConflictException rol bir kullanıcıya atanmışsa
     */
    public function delete(string $uuid): void
    {
        try {
            $deleted = $this->roles->deleteRole($uuid);
        } catch (RecordInUseException $e) {
            // 409 — bu uçta zaten 409 dönüyordu, korunuyor.
            throw new ConflictException($this->translator->trans('role.in_use'), previous: $e);
        }

        if (!$deleted) {
            throw new NotFoundException($this->translator->trans('role.not_found'));
        }
    }

    /**
     * Rolün yetki kümesini TAM KÜME olarak değiştirir (replace).
     *
     * @param list<string> $permissionUuids
     *
     * @throws NotFoundException   rol yoksa
     * @throws ValidationException yetki uuid'lerinden biri geçersizse
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
            // FK ihlali = gövdede var olmayan bir permission uuid'i verilmiş.
            // Transaction geri alındı; rolün eski yetkileri korundu.
            throw new ValidationException(
                ['permissions' => [$this->translator->trans('permission.invalid')]],
                $this->translator->trans('permission.invalid'),
                previous: $e,
            );
        }
    }

    /**
     * Kullanıcıya rol atar/değiştirir (tek-rol).
     *
     * @throws NotFoundException   rol adı bulunamazsa
     * @throws ValidationException hedef kullanıcı yoksa (FK ihlali)
     */
    public function assignToUser(string $userUuid, string $roleName): void
    {
        $role = $this->roles->findByName($roleName);

        if ($role === null) {
            throw new NotFoundException($this->translator->trans('role.not_found'));
        }

        if (!$this->roles->assignUserRole($userUuid, (string) $role['uuid'])) {
            throw new ValidationException(
                ['user_uuid' => [$this->translator->trans('role.assign_failed')]],
                $this->translator->trans('role.assign_failed'),
            );
        }
    }

    // ── Yardımcılar ───────────────────────────────────────────────

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
