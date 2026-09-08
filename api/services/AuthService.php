<?php

namespace Api\Services;

use System\Config\AppConfig;
use System\Config\AuthConfig;
use Api\Models\AuthModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use System\Database\Database;
use System\Exceptions\BadRequestException;
use System\Exceptions\NotFoundException;
use System\Exceptions\UnauthorizedException;
use System\Helpers\Uuid;
use System\Http\Request;
use System\Translation\Contract\TranslatorInterface;

/**
 * Auth servisi — katman sözleşmesi: `docs/api-layer.md`.
 *
 * (Eski atıf `PHPFrame.md §5`'e idi; o dosya hiç var olmadı.)
 *
 * Request-arası durum tutmaz,
 * controller'a bağımlı değildir. Token üretimi/doğrulaması ve bearer çözümü.
 */
class AuthService
{
    public const TOKEN_TYPE_ACCESS  = 'access';
    public const TOKEN_TYPE_REFRESH = 'refresh';

    /**
     * Sır ve TTL'ler artık global sabit değil enjekte edilen config.
     *
     * Önemli bir yan fayda: `SECRET_KEY` sabit olarak okunduğunda, tamamı
     * rakamdan oluşan bir anahtar `Env` tarafından int'e çevriliyordu ve
     * 32 haneli sayısal bir sır PHP_INT_MAX'a taşıyordu — yani yapılandırılan
     * sır ne olursa olsun tüm JWT'ler AYNI anahtarla imzalanıyordu. Typed
     * config sırrı string olarak taşır (bkz. EnvReader::string / Env::raw).
     */
    public function __construct(
        private readonly AuthConfig $auth,
        private readonly AppConfig $app,
        private readonly AuthModel $users,
        private readonly Database $database,
        private readonly TranslatorInterface $translator,
        /**
         * SCOPED bağımlılık — bu yüzden `AuthService` de SCOPED bağlanır
         * (`ApiProvider`). Eskiden `singleton` idi ve header'ı
         * `getallheaders()` ile okuyordu: bağımlılık imzada görünmediği için
         * container ihlali göremiyordu. Artık kenar AÇIK ve
         * `container:validate` onu doğruluyor.
         */
        private readonly Request $request,
    ) {}

    /**
     * Header'daki access token'i decode eder ve sadece type='access' ise user dondurur.
     * Refresh token'larin access yerine kullanilmasi engellenir.
     */
    public function user(): array
    {
        $data = $this->validate(self::TOKEN_TYPE_ACCESS);

        if (isset($data['user'])) {
            return $data['user'];
        }

        return [];
    }


    public function uuid(): string|false
    {
        $user = $this->user();
        return $user['uuid'] ?? false;
    }


    /**
     * Bearer token'i decode eder. $expectedType verilirse payload'un type claim'i
     * ile karsilastirilir; uyumsuzlukta false doner.
     */
    public function validate(?string $expectedType = null): array|false
    {
        $token = $this->bearer();

        if ($token === false) {
            return false;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->auth->secretKey, 'HS256'));
            $payload = json_decode(json_encode($decoded), true);
        } catch (\Exception) {
            return false;
        }

        if ($expectedType !== null && ($payload['type'] ?? null) !== $expectedType) {
            return false;
        }

        return $payload;
    }


    /**
     * Login/register/refresh akislarinda access+refresh token cifti uretir.
     * Iki token farkli 'type' claim'i tasir; access TTL kisa, refresh TTL uzun.
     *
     * ─────────────────────────────────────────────────────────────────────
     * `jti` CLAIM'İ (RFC 7519 §4.1.7) — GÜVENLİK DÜZELTMESİ, kozmetik değil.
     *
     * Eskiden payload yalnızca iss/aud/iat/nbf/exp/type/user içeriyordu ve
     * hepsi aynı saniyede aynı değere sahip oluyordu. Sonuç ÖLÇÜLDÜ: aynı
     * saniyede yapılan iki `token()` çağrısı BİREBİR AYNI JWT'yi üretiyordu.
     *
     * Üç ayrı arıza doğuruyordu:
     *
     *  1. `users_token.access_token_hash` UNIQUE'tir. Aynı saniyedeki iki
     *     login aynı hash'i üretip unique ihlaline düşüyor, ihlal
     *     `catch (PDOException) { return false; }` ile yutuluyor ve
     *     `AuthController::login()` dönüşü kontrol etmediği için kullanıcı
     *     200 + HİÇ KAYDEDİLMEMİŞ token alıyordu.
     *
     *  2. İki farklı oturum birbirinden AYIRT EDİLEMİYORDU: aynı token,
     *     birini iptal etmek diğerini de iptal ediyordu.
     *
     *  3. Refresh rotasyonu aynı saniyede "yeni" token olarak ESKİSİNİN
     *     AYNISINI üretebiliyordu — reuse tespitinin dayandığı benzersizlik
     *     varsayımı bozuluyordu.
     *
     * `jti` her token'ı benzersiz kılarak üçünü birden kapatır. Mevcut
     * token'lar geçerliliğini KORUR: `jti` doğrulamada zorunlu değildir,
     * dolayısıyla açık oturumlar düşmez.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function token(array $users): array
    {
        $now = time();

        // NOT: token, role/permissions dahil full user'ı taşır — bu, FRONTEND'in
        // UI ipuçları için (menü/buton göster-gizle) kullanması içindir, güvenlik
        // kararı için DEĞİL. Backend yetkilendirmesi her zaman DB'den taze okunur
        // (AuthMiddleware → getUserByUuid); token'daki kopyaya güvenilmez.
        $access_token_payload = [
            "iss"  => $this->app->url,
            "aud"  => $this->app->url,
            "iat"  => $now,
            "nbf"  => $now,
            "exp"  => $now + $this->auth->accessTokenExpire,
            "jti"  => Uuid::generate(),
            "type" => self::TOKEN_TYPE_ACCESS,
            "user" => $users,
        ];

        $refresh_token_payload = [
            "iss"  => $this->app->url,
            "aud"  => $this->app->url,
            "iat"  => $now,
            "nbf"  => $now,
            "exp"  => $now + $this->auth->refreshTokenExpire,
            // Access token'dan FARKLI bir jti: ikisi bağımsız kimliklerdir.
            "jti"  => Uuid::generate(),
            "type" => self::TOKEN_TYPE_REFRESH,
            "user" => $users,
        ];

        $data['access_token']  = JWT::encode($access_token_payload, $this->auth->secretKey, 'HS256');
        $data['refresh_token'] = JWT::encode($refresh_token_payload, $this->auth->secretKey, 'HS256');
        $data['expired_at']    = date("Y-m-d H:i:s", $now + $this->auth->accessTokenExpire);
        $data['user']          = $users;

        return $data;
    }

    /**
     * `token()`'ın tipli sarmalayıcısı.
     *
     * @param array<string, mixed> $user
     */
    public function bundle(array $user): TokenBundle
    {
        $data = $this->token($user);

        return new TokenBundle(
            accessToken:  $data['access_token'],
            refreshToken: $data['refresh_token'],
            expiredAt:    $data['expired_at'],
            user:         $data['user'],
        );
    }

    // ── Kullanım senaryoları ──────────────────────────────────────

    /**
     * Kimlik doğrular ve yeni bir oturum açar.
     *
     * @throws BadRequestException kimlik bilgileri hatalıysa (400 KORUNUYOR —
     *         `UnauthorizedException` semantik olarak daha doğru olurdu ama
     *         istemcinin gördüğü kodu değiştirirdi; ayrı bir adıma bırakıldı)
     */
    public function login(string $email, string $password): TokenBundle
    {
        // Varlık kontrolleri BURADA, `Validator`'da değil — gerekçe
        // `AuthController::login()` docblock'unda: her üç başarısızlık da
        // aynı 400 sınıfıdır ve alan-bazlı hata döndürmek hangi e-postanın
        // kayıtlı olduğunu sızdırırdı.
        if ($email === '') {
            throw new BadRequestException($this->translator->trans('auth.email_required'));
        }

        if ($password === '') {
            throw new BadRequestException($this->translator->trans('auth.password_required'));
        }

        $user = $this->users->authenticate($email, $password);

        if ($user === false) {
            throw new BadRequestException($this->translator->trans('auth.invalid_credentials'));
        }

        return $this->startSession($user);
    }

    /**
     * Refresh token'ı döndürür (rotation) ve yeni bir çift üretir.
     *
     * ─────────────────────────────────────────────────────────────────────
     * OAuth 2.0 BCP §4.13 — REFRESH TOKEN ROTATION + REUSE DETECTION.
     *
     * Bu protokol `AuthController::refresh()` İÇİNDE yaşıyordu: 40 satırlık
     * güvenlik mantığı, HTTP yanıtlarıyla iç içe, `exit` ile dallanıyordu ve
     * bir HTTP isteği olmadan çalıştırılamadığı için test edilemezdi.
     *
     * Akış:
     *   1. Bearer token'ın `type` claim'i `refresh` olmalı.
     *   2. Rotation ATOMİKTİR: UPDATE `WHERE refresh_token_hash = :gelen`
     *      koşuluyla yapılır (compare-and-swap). Eşleşme yoksa 0 satır döner.
     *   3. 0 satır → token ya hiç kayıtlı değil YA DA daha önce kullanılmış.
     *      İkincisi (previous_refresh_token_hash ile eşleşme) TOKEN SIZINTISI
     *      göstergesidir: saldırgan çalınmış bir refresh token'ı kullanmıştır.
     *      Bu durumda kullanıcının TÜM oturumları iptal edilir — meşru
     *      kullanıcı yeniden giriş yapar, saldırganın elindeki de ölür.
     * ─────────────────────────────────────────────────────────────────────
     *
     * @throws UnauthorizedException token geçersiz, süresi dolmuş veya tekrar kullanılmışsa
     * @throws NotFoundException     token geçerli ama kullanıcı silinmişse
     */
    public function refresh(): TokenBundle
    {
        $payload      = $this->validate(self::TOKEN_TYPE_REFRESH);
        $refreshToken = $this->bearer();
        $userUuid     = $payload['user']['uuid'] ?? null;

        if ($payload === false || $refreshToken === false || $userUuid === null) {
            throw new UnauthorizedException($this->translator->trans('auth.refresh_invalid'));
        }

        $user = $this->users->getUserByUuid($userUuid);

        if ($user === false) {
            throw new NotFoundException($this->translator->trans('user.not_found'));
        }

        $incomingHash = self::hashToken($refreshToken);
        $new          = $this->bundle($user);

        $rotated = $this->users->rotateRefreshToken(
            $userUuid,
            $incomingHash,
            self::hashToken($new->accessToken),
            self::hashToken($new->refreshToken),
        );

        if (!$rotated) {
            if ($this->users->isRefreshTokenReused($userUuid, $incomingHash)) {
                // Sızıntı tespit edildi — tüm oturumlar iptal.
                $this->users->removeRefreshToken($userUuid);
            }

            throw new UnauthorizedException($this->translator->trans('auth.refresh_token_invalid'));
        }

        return $new;
    }

    /**
     * Kullanıcının tüm oturumlarını kapatır.
     *
     * İDEMPOTENT: silinecek satır olmaması hata değildir. Eski davranış
     * `rowCount() === 0` durumunda 400 döndürüyordu — yani iki kez logout
     * çağıran bir istemci ikincisinde hata alıyordu, oysa istenen son durum
     * (oturum yok) sağlanmıştı. Bu, `deleteAvatar` gibi diğer idempotent
     * uçlarla da tutarsızdı.
     */
    public function logout(string $userUuid): void
    {
        $this->users->removeRefreshToken($userUuid);
    }

    /**
     * Token çifti üretir ve oturum satırını ATOMİK olarak değiştirir.
     *
     * Transaction ZORUNLU: `replaceToken()` bir DELETE + bir INSERT yapar.
     * Araya girecek bir hata, kullanıcıyı oturum satırı OLMADAN bırakırdı —
     * elinde geçerli görünen ama `validAccessToken()` kontrolünden geçmeyen
     * bir token kalırdı.
     *
     * PUBLIC çünkü `login()` dışında KAYIT akışı da oturum açar
     * (`UserController::register`). O akış eskiden `token()` +
     * `addOrUpdateRefreshToken()` ikilisini elle çağırıyor ve dönüş değerini
     * kontrol etmiyordu — yani aynı sessiz-başarısızlık hatasını taşıyordu.
     * Tek giriş noktası ikisini birden kapatır.
     *
     * @param array<string, mixed> $user
     */
    public function startSession(array $user): TokenBundle
    {
        $bundle = $this->bundle($user);

        $this->database->transaction(function () use ($user, $bundle): void {
            $this->users->replaceToken(
                (string) $user['uuid'],
                self::hashToken($bundle->accessToken),
                self::hashToken($bundle->refreshToken),
            );
        });

        return $bundle;
    }


    /**
     * İstekteki bearer token — yoksa `false`.
     *
     * Header okuma `Request`'e DEVREDİLDİ. Eskiden burada çıplak
     * `getallheaders()` çağrısı vardı; sonuçları:
     *
     *   • `AuthService` `singleton` olarak bağlıydı ama istek-başına global
     *     durum okuyordu. Bağımlılık constructor imzasında GÖRÜNMEDİĞİ için
     *     `ScopeValidator` bu Singleton→Scoped kenarını yakalayamıyordu —
     *     tam olarak engellemek üzere tasarlandığı hata sınıfı.
     *   • `getallheaders()` her SAPI'de yoktur ve `Request` zaten
     *     `$_SERVER['HTTP_*']` fallback'i, harf-duyarsız arama ve
     *     `REDIRECT_HTTP_AUTHORIZATION` telafisi uyguluyordu. İki ayrı header
     *     okuma yolu, iki farklı sağlamlık seviyesi demekti.
     *
     * Dönüş tipi `string|false` KORUNDU: çağıranlar (`validate()`,
     * `AuthMiddleware`, `AuthController`) bu sözleşmeye göre yazılmış durumda.
     */
    public function bearer(): string|false
    {
        return $this->request->token() ?? false;
    }


    /**
     * Token'in DB lookup'ında kullanilan kararli hash'i. SHA-256 hex (64 char).
     * Plaintext token'larin DB'de saklanmasi yerine hash karsilastirmasi yapilir.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
