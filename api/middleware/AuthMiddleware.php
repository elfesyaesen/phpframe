<?php

declare(strict_types=1);

namespace Api\Middleware;

use Api\Models\AuthModel;
use Api\Services\AuthService;
use Closure;
use System\Http\Request;
use System\Exceptions\UnauthorizedException;
use System\Http\Response;
use System\Middleware\Interface\MiddlewareInterface;
use System\Translation\Contract\TranslatorInterface;

/**
 * API Authentication Middleware
 *
 * Korumali route'larda:
 *  1. JWT imzasi/expiry dogrulanir
 *  2. Token type === 'access' kontrolu (refresh token'in access yerine kullanimi engellenir)
 *  3. Token DB'de kayitli mi (logout/revocation icin gerekli)
 *  4. Kullanici DB'den TAZE cekilir; userUuid + authUser Request'e yazilir
 *
 * Yetki neden DB'den? JWT claim'leri bayat olabilir: token verildikten sonra
 * kullanicinin rolu degistiyse/iptal edildiyse veya kullanici silindiyse, JWT hala
 * eski state'i tasir. Yetkilendirme her zaman canli veriye dayanmali — bu yuzden
 * token'daki rol/yetki DEGIL, DB'deki guncel deger (Gate) kullanilir.
 *
 * Basarisizlikta 401 + auth.required mesaji ile request sonlandirilir.
 */
class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Bağımlılıklar CONSTRUCTOR'DAN gelir.
     *
     * Eskiden `ContainerAwareTrait` ile container setter'la enjekte ediliyor
     * ve servisler `$this->container->get(...)` ile çekiliyordu. Middleware
     * container tarafından çözüldüğü için buna hiç gerek yoktu; sonuçları:
     *
     *   • Gerçek bağımlılıklar imzada görünmüyordu → derleyici doğrulayamıyor,
     *     `container:debug AuthMiddleware` sıfır dep gösteriyordu.
     *   • Container set edilmezse `handle()` "Call to a member function get()
     *     on null" ile patlıyordu — yani ÇALIŞMA ZAMANI hatası, oysa artık
     *     eksik bir bağımlılık DERLEME hatası.
     */
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuthModel $authModel,
        private readonly TranslatorInterface $translator,
    ) {}

    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        // 1+2: JWT decode + type='access' kontrolu
        $payload = $this->auth->validate(AuthService::TOKEN_TYPE_ACCESS);
        $userUuid = $payload['user']['uuid'] ?? null;

        if ($userUuid === null) {
            $this->unauthorized();
        }

        // 3: Token DB'de kayitli mi? (logout/revocation)
        $token = $this->auth->bearer();
        if ($token === false) {
            $this->unauthorized();
        }

        if (!$this->authModel->validAccessToken($userUuid, AuthService::hashToken($token))) {
            $this->unauthorized();
        }

        // 4: Kullaniciyi DB'den TAZE cek. Kullanici silinmis/pasifse (false) erisim reddedilir
        // — token suresi dolmasa bile aninda devre disi kalir. Rol/yetki kararini Gate verir.
        $user = $this->authModel->getUserByUuid($userUuid);
        if ($user === false) {
            $this->unauthorized();
        }

        // Tek auth-state kaynagi Request'tir; Gate ve yetki middleware'leri buradan okur
        // (enjekte edilebilen $_REQUEST global'i ve bayat JWT claim'leri KULLANILMAZ).
        $request->setUserUuid($userUuid);
        $request->setAuthUser($user);

        $next($request);
    }

    /**
     * İsteği reddeder.
     *
     * ─────────────────────────────────────────────────────────────────────
     * ARTIK `jsonResponse` + `exit` DEĞİL, İSTİSNA.
     *
     * İki şeyi birden düzeltir:
     *
     * 1. ZARF TUTARSIZLIĞI. `RoleMiddleware` ve `PermissionMiddleware` zaten
     *    `ForbiddenException` fırlatıp `{"error":{...}}` üretiyordu; bu
     *    middleware ise `{"message":"..."}` üretiyordu. API, REDDEDEN
     *    KATMANA GÖRE iki farklı hata biçimi döndürüyordu.
     *
     * 2. `exit` BAĞIMLILIĞI. Metot `never` olarak tiplenmişti ama bunu
     *    yalnızca `exit` sayesinde sağlıyordu. `Response`'tan `exit`
     *    kaldırıldığında bu imza bir YALANA dönüşecekti — ve dönüş,
     *    kimlik doğrulaması başarısız bir isteğin controller'a AKMASI
     *    anlamına gelirdi. Fırlatmak `never`'ı gerçek kılar.
     * ─────────────────────────────────────────────────────────────────────
     */
    private function unauthorized(): never
    {
        throw new UnauthorizedException($this->translator->trans('auth.required'));
    }
}
