<?php

declare(strict_types=1);

namespace Monitor\Middleware;

use Closure;
use System\Config\MonitorConfig;
use System\Exceptions\NotFoundException;
use System\Http\Request;
use System\Http\Response;
use System\Middleware\Interface\MiddlewareInterface;

/**
 * Monitor dashboard'ı için paylaşılan-sır kontrolü.
 *
 * TEK işi token doğrulamak. Kimlik doğrulama ve rol kontrolü mevcut
 * `api_auth` / `api_role:administrator` middleware'lerine bırakılır; hangi
 * kombinasyonun uygulanacağını `MONITOR_AUTH_MODE` belirler ve middleware
 * listesi `routes/routes.php`'de kurulur. Böylece burada AuthMiddleware'in
 * mantığı kopyalanmaz (DRY) ve politika tek yerde okunur.
 *
 * ## Token üç kaynaktan okunur: header, cookie, query
 *
 * Tarayıcı istek header'ı gönderemez. Token'ı her dahili linke gömmek de
 * çözüm değil: o zaman sır adres çubuğunda, tarayıcı geçmişinde, web sunucusu
 * access log'unda ve dışa giden `Referer` header'ında sürekli dolaşır —
 * ayrıca bir linki (ör. "sıfırla") token'sız yazmak kullanıcıyı 404'e atar.
 *
 * Bu yüzden query parametresi TEK SEFERLİK DEVİR olarak kullanılır:
 * `?token=...` doğrulanır, kapsamı `/monitor` ile sınırlı bir HttpOnly
 * cookie'ye yazılır ve istemci token'sız temiz URL'ye yönlendirilir.
 * Sonraki tüm gezinme cookie ile çalışır, dolayısıyla şablonlardaki linkler
 * token taşımak zorunda değildir ve sır URL'lerde birikmez.
 */
final class MonitorTokenMiddleware implements MiddlewareInterface
{
    private const HEADER = 'X-Monitor-Token';
    private const COOKIE = 'monitor_token';
    private const QUERY  = 'token';

    /**
     * Cookie yalnızca /monitor altına gönderilir: uygulamanın geri kalanına
     * (ve üçüncü parti isteklerine) sızmaz.
     */
    private const COOKIE_PATH = '/monitor';

    /**
     * Beklenen token artık `MONITOR_TOKEN` sabitinden değil enjekte edilen
     * config'ten okunur (DI-plan §25).
     *
     * Eskiden `defined('MONITOR_TOKEN') ? (string) MONITOR_TOKEN : ''`
     * yazıyordu; sabit yoksa boş string'e düşüyordu. Aşağıdaki fail-closed
     * kontrolü sayesinde bu erişim vermiyordu, ama "config yüklenmedi" ile
     * "token ayarlanmadı" ayrımı kayboluyordu.
     */
    public function __construct(
        private readonly MonitorConfig $monitor,
    ) {}

    #[\Override]
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void
    {
        $expected = $this->monitor->token;

        // Token yapılandırılmamışsa token modunda erişim VERİLMEZ (fail-closed).
        // Boş string ile boş string'i karşılaştırıp herkesi içeri almak, en
        // sessiz güvenlik hatası türüdür.
        if ($expected === '') {
            $this->deny('monitor_token_not_configured');
        }

        // 1) Header — araçlar (curl, izleme sistemleri) için birincil yol.
        $header = $request->header(self::HEADER);
        if (is_string($header) && $header !== '' && hash_equals($expected, $header)) {
            $next($request);

            return;
        }

        // 2) Cookie — tarayıcı gezinmesi (devirden sonra).
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '' && hash_equals($expected, $cookie)) {
            $next($request);

            return;
        }

        // 3) Query — tek seferlik devir. Doğruysa cookie yazılır ve token
        //    URL'den düşürülerek yönlendirilir; böylece sır adres çubuğunda
        //    ve sonraki isteklerin Referer'ında kalmaz.
        $query = $request->query(self::QUERY);
        if (is_string($query) && $query !== '' && hash_equals($expected, $query)) {
            $this->storeCookie($query);
            $response->redirect($this->cleanUrl());

            // `return` ZORUNLU — eskiden `Response::redirect()` `exit` ettiği
            // için gerekmiyordu. `exit` kaldırıldığında bu satırın yokluğu
            // yönlendirmeden sonra `deny()`'a düşülmesine ve 302 yerine 404
            // dönülmesine yol açtı: token devri tamamen kırılmıştı.
            //
            // Middleware'ler `$next()` çağırmadan dönerek zinciri kısa devre
            // yapar; burada da istenen budur (yanıt zaten gönderildi).
            return;
        }

        $this->deny('monitor_token_mismatch');
    }

    /**
     * Oturum cookie'si (kapanışta silinir), HttpOnly ve SameSite=Strict.
     *
     * HttpOnly: sayfada XSS olsa bile token JavaScript'e okunamaz.
     * SameSite=Strict: başka sitelerden gelen isteklerde gönderilmez.
     * Secure: HTTPS üzerindeyken şart (aksi halde ağda düz metin gider);
     * yerel http geliştirmede cookie'nin hiç çalışmaması için zorlanmaz.
     */
    private function storeCookie(string $token): void
    {
        setcookie(self::COOKIE, $token, [
            'expires'  => 0,
            'path'     => self::COOKIE_PATH,
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Geçerli URL'in token parametresi çıkarılmış hâli.
     */
    private function cleanUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/monitor';
        $uri = is_string($uri) ? $uri : '/monitor';

        $path  = strtok($uri, '?');
        $path  = ($path === false || $path === '') ? '/monitor' : $path;
        $queryString = parse_url($uri, PHP_URL_QUERY);

        if (!is_string($queryString) || $queryString === '') {
            return $path;
        }

        parse_str($queryString, $params);
        unset($params[self::QUERY]);

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }

    private function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * 403 yerine 404: yanlış/eksik token, dashboard'ın VARLIĞINI doğrulamaz.
     * Monitor kapalıyken route'un hiç tanımlanmaması da (routes.php) aynı
     * 404'ü ürettiği için iki durum dışarıdan ayırt edilemez.
     */
    private function deny(string $reason): never
    {
        throw new NotFoundException(
            message: 'Sayfa bulunamadı',
            context: ['reason' => $reason]
        );
    }
}
