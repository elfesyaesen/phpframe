<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Services\AvatarService;
use Api\Services\UserService;
use System\Engine\BaseController;
use System\Helpers\Status;
use System\Validation\Validator;

/**
 * Kullanıcı hesabı uçları: kayıt, profil, parola, hesap silme, avatar.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Katman sözleşmesi (docs/api-layer.md): burada YALNIZCA HTTP vardır.
 *
 * Bu sınıf refactor öncesinde kod tabanının en yoğun noktasıydı: 276 satır,
 * 7 constructor bağımlılığı, 26 `jsonResponse` çağrısı ve içinde parola
 * hash'leme, parola ÜRETME (`generatePassword`), dosya depolama anahtarı
 * kurma, elle yazılmış iki-fazlı commit ve e-posta numaralandırma politikası.
 *
 * Şimdi üç bağımlılık kaldı: `UserService`, `AvatarService`, `Validator`.
 * ─────────────────────────────────────────────────────────────────────────
 */
class UserController extends BaseController
{
    public function __construct(
        private readonly UserService $Users,
        private readonly AvatarService $Avatar,
        private readonly Validator $Validator,
    ) {
    }

    public function register(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'username'              => 'required|string|alpha_num|min:3|max:50|unique_active:user,username',
            'email'                 => 'required|email|unique_active:user,email',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
            'firstname'             => 'nullable|string|max:100',
            'lastname'              => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:20',
        ]);

        $bundle = $this->Users->register($validated);

        $this->response()->jsonResponse(Status::CREATED, $bundle->toArray());
        return;
    }

    /**
     * Parola sıfırlama talebi.
     *
     * E-POSTA NUMARALANDIRMA KORUMASI: adres kayıtlı olsun olmasın AYNI yanıt
     * döner. `requestPasswordReset()` `void` olduğu için controller "bulundu
     * mu" bilgisine ERİŞEMEZ — koruma tip sistemiyle zorunlu kılınmıştır,
     * unutulabilecek bir `if` değildir.
     */
    public function resetPassword(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'email' => 'required|email',
        ]);

        $this->Users->requestPasswordReset($validated['email']);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_reset_sent')]
        );
        return;
    }

    /**
     * Sıfırlama linkindeki token'ı kullanarak yeni parolayı yazar.
     *
     * `token` kuralı tam bir hex deseni: token 32 byte'ın hex karşılığıdır,
     * yani uzunluğu SABİT 64 ve alfabesi `[a-f0-9]`. Şekli doğrulayıcıda
     * kesmek, DB'ye hiç gitmeden 422 döner. (`size` kuralı `RuleFactory`'de
     * YOK — uzunluk da desenin içinde ifade edildi.)
     *
     * `unique_active` GİBİ bir kural YOK ve olmamalı: parola kuralları
     * `register`/`changePassword` ile birebir aynı tutulur, yoksa
     * sıfırlama yolu politikanın arka kapısı olur.
     */
    public function confirmResetPassword(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'token'                 => 'required|string|regex:/^[a-f0-9]{64}$/',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->Users->confirmPasswordReset($validated['token'], $validated['password']);

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_reset_done')]
        );
        return;
    }

    /**
     * Sıfırlama e-postasındaki 6 haneli KODU kullanarak yeni parolayı yazar.
     *
     * ─────────────────────────────────────────────────────────────────────
     * NEDEN AYRI BİR UÇ: `confirmResetPassword()` ile aynı işi yapıyor, tek
     * fark sırrın biçimi. Tek uçta birleştirmek `token` ile `email+code`
     * arasında koşullu doğrulama gerektirirdi — `RuleFactory`'de
     * `required_without` gibi bir kural YOK, yani koşul controller'ın içine
     * elle yazılırdı. Katman sözleşmesi (docs/api-layer.md) controller'a
     * "doğrulama kuralı dizileri" veriyor, kural MANTIĞI vermiyor. İki uç,
     * iki statik dizi.
     *
     * `email` NEDEN İSTENİYOR: kod tek başına aranamaz — gerekçesi
     * `UserService::confirmPasswordResetWithCode()` yorumunda (kısaca: koda
     * göre global arama, tek denemeyle "o kodu bekleyen herhangi bir hesabı"
     * ele geçirme yolu açardı).
     *
     * `regex:/^[0-9]{6}$/` — tam 6 hane. `integer` KULLANILMAZ: baştaki
     * sıfırlar korunmak zorunda ("012345" geçerli bir koddur) ve sayıya
     * çevirmek onları yok eder.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function confirmResetPasswordWithCode(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'email'                 => 'required|email',
            'code'                  => 'required|string|regex:/^[0-9]{6}$/',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->Users->confirmPasswordResetWithCode(
            $validated['email'],
            $validated['code'],
            $validated['password']
        );

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_reset_done')]
        );
        return;
    }

    public function changePassword(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'current_password'      => 'required|string',
            'password'              => 'required|password:6,simple|confirmed',
            'password_confirmation' => 'required',
        ]);

        $this->Users->changePassword(
            (string) $this->request()->userUuid(),
            $validated['current_password'],
            $validated['password'],
        );

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.password_updated')]
        );
        return;
    }

    public function me(): void
    {
        $this->response()->jsonResponse(
            Status::OK,
            $this->Users->profile((string) $this->request()->userUuid())
        );
        return;
    }

    public function updateProfile(): void
    {
        $body = $this->request()->json();

        $validated = $this->Validator->validate($body, [
            'firstname' => 'nullable|string|max:100',
            'lastname'  => 'nullable|string|max:100',
            'phone'     => 'nullable|string|max:20',
        ]);

        // HAM gövde de geçilir: "alanı null yap" ile "alanı hiç gönderme"
        // ayrımını yalnızca o taşır (PATCH semantiği). `$validated` yalnızca
        // kural tanımlı alanları içerir ve gönderilmemiş bir alanı
        // gönderilmiş gibi gösteremez.
        $profile = $this->Users->updateProfile(
            (string) $this->request()->userUuid(),
            $body,
            $validated,
        );

        $this->response()->jsonResponse(Status::OK, $profile);
        return;
    }

    public function deleteAccount(): void
    {
        $validated = $this->Validator->validate($this->request()->json(), [
            'current_password' => 'required|string',
        ]);

        $this->Users->deleteAccount(
            (string) $this->request()->userUuid(),
            $validated['current_password'],
        );

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('user.account_deleted')]
        );
        return;
    }

    public function uploadAvatar(): void
    {
        $this->Avatar->upload(
            (string) $this->request()->userUuid(),
            $this->request()->files('avatar')
        );

        $this->response()->jsonResponse(Status::CREATED, []);
        return;
    }

    public function deleteAvatar(): void
    {
        $this->Avatar->remove((string) $this->request()->userUuid());

        $this->response()->jsonResponse(Status::OK, []);
        return;
    }

    /**
     * Kullanıcının KENDİ avatarını akıtır.
     *
     * `jsonResponse` KULLANMAZ — tek binary/akış ucu budur. `Response`'tan
     * `exit` kaldırıldığında (adım 10) bu bir `StreamedResponse` döndürecek.
     */
    public function serveAvatar(): void
    {
        $this->Avatar->stream((string) $this->request()->userUuid());
        return;
    }
}
