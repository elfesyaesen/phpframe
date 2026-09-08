<?php

declare(strict_types=1);

namespace System\Security\Contract;

/**
 * Yetki kontrolü cephesi.
 *
 * NEDEN FRAMEWORK KATMANINDA: `BaseController::can()` / `authorize()` yetki
 * kontrolü sunuyor ama somut uygulama (`Api\Security\Gate`) UYGULAMA
 * katmanında. Somut sınıfa tip vermek framework'ü uygulama modülüne bağımlı
 * yapardı — api modülü olmayan bir kurulumda `BaseController` yüklenemezdi.
 *
 * Arayüz bağımlılık yönünü doğru çevirir: framework sözleşmeyi tanımlar,
 * uygulama onu karşılar ve `ApiProvider` bağlar (DI-plan §11).
 *
 * Yalnızca controller'ların kullandığı metotları içerir; `Gate`'in rol
 * sorgulama metotları (`role()`, `hasRole()`) uygulamaya özgü kalır.
 */
interface GateInterface
{
    /**
     * Aktif kullanıcı bu izne sahip mi?
     */
    public function allows(string $permission): bool;

    /**
     * `allows()`'un olumsuzu — okunabilirlik için.
     */
    public function denies(string $permission): bool;

    /**
     * İzin yoksa yetki istisnası fırlatır.
     */
    public function authorize(string $permission): void;
}
