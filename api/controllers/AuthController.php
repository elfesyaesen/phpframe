<?php

declare(strict_types=1);

namespace Api\Controllers;

use Api\Services\AuthService;
use Api\Services\UserService;
use System\Engine\BaseController;
use System\Helpers\Status;

/**
 * Oturum uçları: login, refresh, logout, me.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Katman sözleşmesi (docs/api-layer.md): burada YALNIZCA HTTP vardır.
 *
 * `refresh()` eskiden 40 satırlık bir OAuth 2.0 rotation + reuse-detection
 * protokolü barındırıyordu — token hash'leme, atomik compare-and-swap,
 * "sızıntı tespit edildi, tüm oturumları iptal et" kararı, hepsi HTTP
 * yanıtlarıyla iç içeydi ve `exit` ile dallanıyordu. O mantık artık
 * `AuthService::refresh()` içinde ve bir HTTP isteği olmadan çalıştırılabilir.
 * ─────────────────────────────────────────────────────────────────────────
 */
class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $auth,
        /**
         * Profil projeksiyonu için — `AuthModel` DEĞİL.
         *
         * `me()` artık `/users/me` ile aynı şekli döndürüyor; ikisi de
         * `UserService::profile()` üzerinden gidiyor, dolayısıyla ayrışamazlar.
         */
        private readonly UserService $users,
    ) {
    }

    public function login(): void
    {
        // ── LOGIN BİLİNÇLİ OLARAK `Validator` KULLANMAZ ──────────────────
        //
        // İlk denemede `Validator` kullanıldı ve eksik alan yanıtı 400'den
        // 422'ye kaydı — ölçüldü, geri alındı. Plan durum kodlarını korumayı
        // şart koşuyor: bu iş zaten hata ZARFINI değiştiriyor, KODLARI da
        // aynı anda değiştirmek bir regresyonun hangi değişiklikten geldiğini
        // ayırt edilemez kılardı.
        //
        // Kod korunurken ortaya çıkan ikinci gerekçe daha güçlü: "e-posta
        // eksik", "parola eksik" ve "kimlik bilgileri hatalı" AYNI SINIF
        // başarısızlıktır (400). `Validator` bunları alan-bazlı 422'ye
        // çevirseydi, yanıt hangi alanın kabul edildiğini SIZDIRIRDI —
        // e-posta numaralandırmasına yarayan bir fark.
        //
        // Varlık kontrolü servistedir; controller yalnızca ham girdiyi iletir.
        $body = $this->request()->json();

        $bundle = $this->auth->login(
            (string) ($body['email'] ?? ''),
            (string) ($body['password'] ?? ''),
        );

        $this->response()->jsonResponse(Status::OK, $bundle->toArray());
        return;
    }

    /**
     * Refresh token rotation.
     *
     * Bearer header'da REFRESH token beklenir (access değil).
     */
    public function refresh(): void
    {
        $bundle = $this->auth->refresh();

        $this->response()->jsonResponse(Status::OK, $bundle->toArray());
        return;
    }

    /**
     * Kullanıcının tüm aktif oturumlarını kapatır.
     *
     * DAVRANIŞ DEĞİŞİKLİĞİ: idempotent hale geldi. Eskiden silinecek satır
     * yoksa 400 dönüyordu — iki kez logout çağıran bir istemci ikincisinde
     * hata alıyordu, oysa istenen son durum (oturum yok) sağlanmıştı.
     * Artık her iki durumda da 200.
     */
    public function logout(): void
    {
        $this->auth->logout((string) $this->request()->userUuid());

        $this->response()->jsonResponse(
            Status::OK,
            ['message' => $this->translator()->trans('auth.logout_success')]
        );
        return;
    }

    /**
     * Kimliği doğrulanmış kullanıcı (korumalı route).
     * Middleware token + type + DB-kayıtlı doğrulamasını hallediyor.
     *
     * ── `/v1/api/users/me` İLE AYNI ŞEKLİ DÖNDÜRÜR ──────────────────────
     *
     * Kod tabanında "mevcut kullanıcı" için İKİ uç vardı ve İKİ FARKLI şekil
     * döndürüyorlardı: bu uç `AuthModel::getUserByUuid()` (8 alan),
     * `/users/me` ise `UserModel::getProfile()` (9 alan — `avatar_url` da
     * var). Frontend hangi ucu çağırdığına göre farklı bir sözleşme
     * görüyordu.
     *
     * Uç SİLİNMEDİ — bu kırıcı olurdu (mevcut istemciler 404 alırdı).
     * Bunun yerine aynı projeksiyona bağlandı: yanıt bir ALAN KAZANIR, ki bu
     * geriye uyumludur (istemciler bilmedikleri alanı yok sayar).
     *
     * Uzun vadede uçlardan biri sürümlenmiş bir deprecation ile kaldırılmalı.
     */
    public function me(): void
    {
        $this->response()->jsonResponse(
            Status::OK,
            $this->users->profile((string) $this->request()->userUuid())
        );
        return;
    }
}
