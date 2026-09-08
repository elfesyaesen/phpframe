<?php

declare(strict_types=1);

namespace Api\Services;

use Api\Exceptions\DuplicateNameException;
use Api\Models\PermissionModel;
use System\Exceptions\NotFoundException;
use System\Exceptions\ValidationException;
use System\Translation\Contract\TranslatorInterface;

/**
 * Yetki yönetimi kullanım senaryoları.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * KATMAN SÖZLEŞMESİ (docs/api-layer.md):
 *
 *   • Model'leri orkestre eder; `Request`/`Response`/PDO'ya DOKUNMAZ.
 *   • Başarısızlığı `System\Exceptions\*` FIRLATARAK bildirir — asla `false`
 *     ya da `['error'=>...]` döndürmez.
 *   • Kullanıcıya gidecek mesajları BURADA çevirir.
 *
 * Çeviri neden servis katmanında: `ExceptionHandler` bootstrap'ta kaydedilen
 * bir SINGLETON'dır ve aktif locale'i yoktur. Ona `Translator` vermek, scoped
 * bir servisi singleton'a enjekte etmek olurdu — `ScopeValidator`'ın haklı
 * olarak reddettiği kenar. Locale'i bilen en dış katman servistir.
 * ─────────────────────────────────────────────────────────────────────────
 */
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
     * Yeni yetki oluşturur; oluşan uuid'i döner.
     *
     * @throws ValidationException ad zaten kullanımdaysa
     */
    public function create(string $name, ?string $description): string
    {
        try {
            return $this->permissions->createPermission($name, $description);
        } catch (DuplicateNameException $e) {
            // 422 KORUNUYOR (409 değil).
            //
            // `ConflictException` semantik olarak daha doğru olurdu, ama bu
            // uçtan bugün 422 dönüyor ve istemciler ona göre yazılmış. Bu iş
            // zaten hata ZARFINI değiştiriyor; durum KODUNU da aynı anda
            // değiştirmek, bir regresyonun hangi değişiklikten geldiğini
            // ayırt edilemez kılardı. Kod değişimi ayrı, sürümlenmiş bir adım.
            throw new ValidationException(
                ['name' => [$this->translator->trans('permission.name_taken')]],
                $this->translator->trans('permission.name_taken'),
                previous: $e,
            );
        }
    }

    /**
     * Yetkiyi siler.
     *
     * @throws NotFoundException yetki yoksa
     */
    public function delete(string $uuid): void
    {
        if (!$this->permissions->deletePermission($uuid)) {
            throw new NotFoundException($this->translator->trans('permission.not_found'));
        }
    }

    /**
     * Kullanıcıya özel yetki override'ı ayarlar.
     *
     * `$effect === null` override'ı KALDIRIR (idempotent).
     *
     * @throws NotFoundException   yetki adı bulunamazsa
     * @throws ValidationException hedef kullanıcı yoksa (FK ihlali)
     */
    public function setUserOverride(string $userUuid, string $permissionName, ?string $effect): void
    {
        $permission = $this->permissions->findByName($permissionName);

        if ($permission === null) {
            throw new NotFoundException($this->translator->trans('permission.not_found'));
        }

        if (!$this->permissions->setUserOverride($userUuid, (string) $permission['uuid'], $effect)) {
            // Buraya yalnızca FK ihlalinde düşülür (model gerçek arızaları
            // yukarı fırlatır) — yani "böyle bir kullanıcı yok".
            throw new ValidationException(
                ['user_uuid' => [$this->translator->trans('permission.override_failed')]],
                $this->translator->trans('permission.override_failed'),
            );
        }
    }
}
