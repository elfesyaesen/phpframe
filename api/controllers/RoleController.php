<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Services\RoleService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

/**
 * Rol yönetimi (admin). Roller çalışma zamanında oluşturulup düzenlenebilir.
 *
 * Route grubu `api_auth` + `api_role:administrator` ile korunur (route-level).
 * Güvenlik: is_superadmin API'den AYARLANAMAZ (`RoleModel::createRole` daima
 * 0 yazar) — privilege escalation engeli.
 *
 * Katman sözleşmesi: burada YALNIZCA HTTP vardır (bkz. docs/api-layer.md).
 * Hata yolları servis istisnalarıyla, başarı yolları `return` ile biter.
 */
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
        // ── BİLİNÇLİ, KÜÇÜK BİR DAVRANIŞ DEĞİŞİKLİĞİ ────────────────────
        // Eskiden varlık kontrolü doğrulamadan ÖNCE yapılıyordu; bilinmeyen
        // bir uuid'e geçersiz gövde gönderildiğinde yanıt 404 oluyordu.
        // Artık önce doğrulama koşuyor, yani o durumda 422 dönüyor.
        //
        // Gerekçe: `update` ve `syncPermissions` bu sırayla kod tabanındaki
        // TEK istisnaydı — `store`, `assignToUser` ve tüm diğer uçlar önce
        // doğruluyor. Varlık kontrolü artık servisin içinde olduğundan,
        // eski sırayı korumak controller'ın "bu rol var mı?" diye bir domain
        // sorgusu yapmasını gerektirirdi ki bu tam olarak kaldırdığımız şey.
        //
        // Etki dar: yalnızca "bilinmeyen uuid + geçersiz gövde" bileşimi;
        // her iki hata da zaten 4xx ve istemci gövdesini düzeltmek zorunda.
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

    /**
     * Rolün yetki kümesini tam-küme olarak değiştirir (replace).
     * Body: { "permissions": ["<permission-uuid>", ...] }
     */
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

    /**
     * Kullanıcıya rol atar/değiştirir (tek-rol).
     * Body: { "role": "<rol adı>" }
     */
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
