<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Services\PermissionService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

/**
 * Yetki yönetimi (admin) + kullanıcıya özel (+/−) override.
 *
 * Route grubu `api_auth` + `api_role:administrator` ile korunur (route-level);
 * bu yüzden burada ayrıca `Gate` çağrısı YOKTUR.
 *
 * Not: Yetki RENAME bilinçli olarak yoktur — yetki adı kod/route'larda tam-ad
 * eşleşmesiyle kullanıldığından yeniden adlandırma kırılma riski taşır.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * KATMAN SÖZLEŞMESİ (docs/api-layer.md): burada YALNIZCA HTTP vardır.
 * Request oku → TEK servis çağrısı → yanıt. Domain sonucu üzerinde koşul yok.
 *
 * Hata yolları artık `if (!$sonuc) { jsonResponse(404, ...) }` DEĞİL: servis
 * tipli istisna fırlatır, `ExceptionHandler` onu doğru durum koduna çevirir.
 * Bu, controller'daki 8 çağrı noktasını 4'e indirdi ve kalan dördü de
 * `return` ile bitiyor — böylece `Response`'tan `exit` kaldırıldığında bu
 * dosyada düzeltilecek bir şey kalmayacak.
 * ─────────────────────────────────────────────────────────────────────────
 */
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

    /**
     * Kullanıcıya özel yetki override'ı ayarlar.
     * Body: { "permission": "<yetki adı>", "effect": "grant" | "deny" | null }
     * effect null → override kaldırılır.
     */
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
