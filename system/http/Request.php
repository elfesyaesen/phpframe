<?php

declare(strict_types=1);

namespace System\Http;

use System\Config\HttpConfig;

use JsonException;

class Request
{
    public readonly array $headers;
    public readonly array $files;
    public readonly array $server;
    public readonly array $request;
    private ?array $jsonCache = null;
    private ?string $userUuid = null;
    private ?array $authUser = null;

    /**
     * Güvenilir proxy IP'leri.
     *
     * Artık `TRUSTED_PROXIES` sabitinden DEĞİL, enjekte edilen
     * `HttpConfig`'ten gelir. Eskiden `isTrustedProxy()` içinde
     * `defined('TRUSTED_PROXIES')` kontrolü yapılıyor ve sabit yoksa sınıf
     * içi varsayılana düşülüyordu — yani "hangi proxy'lere güveniliyor"
     * sorusunun cevabı config'in yüklenip yüklenmediğine bağlıydı. Bu bir
     * GÜVENLİK ayarı (X-Forwarded-For doğrulaması) olduğu için belirsiz
     * kalmaması gerekir.
     *
     * @var array<string>
     */
    private array $trustedProxies;

    public function __construct(?HttpConfig $http = null)
    {
        // Config opsiyonel: `Request` bazı testlerde ve erken boot
        // yollarında config olmadan da kurulabilmeli. Verilmezse güvenli
        // varsayılan (yalnızca localhost) kullanılır — asla "her proxy'ye
        // güven" değil.
        $this->trustedProxies = $http?->trustedProxies ?? ['127.0.0.1', '::1'];

        // $_SERVER kullanıcı form girdisi değildir (server/proxy kontrollü); ayrıca
        // header'lar getallheaders() ile zaten sanitize edilmeden alınıyor. Tüm
        // $_SERVER'ı her request'te recursive sanitize etmek (40+ giriş × regex)
        // gereksiz hot-path maliyetidir. Kullanılan anahtarlar (REMOTE_ADDR,
        // REQUEST_URI/METHOD, X-Forwarded-For) zaten kullanım yerinde doğrulanır.
        $this->server  = $_SERVER;
        $this->headers = $this->parseHeaders();
        $this->files   = $_FILES;
        // Yalnızca gerçek kullanıcı girdisi (GET + POST + JSON) sanitize edilir.
        $this->request = $this->sanitize(
            [...$_GET, ...$_POST, ...$this->parseJsonBody()]
        );
    }

    public function files(string $key): array|null
    {
        return $this->files[$key] ?? null;
    }


    /**
     * $_SERVER içindeki belirli bir server değerini döndürür.
     */
    public function server(string $key): string|null
    {
        return $this->server[$key] ?? null;
    }


    /**
     * Genel input (GET + POST + JSON) içinden bir değer getirir.
     * Varsayılan değer döndürme özelliği vardır.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }


    /**
     * Header key değerini döndürür.
     */
    public function header(string $key): string|null
    {
        // BÜYÜK/KÜÇÜK HARF DUYARSIZ — zorunlu, tercih değil.
        //
        // RFC 9110 başlık adlarını harf-duyarsız tanımlar ve HTTP/2 ile HTTP/3
        // adları TAMAMEN KÜÇÜK HARF gönderir. Kaynağa göre de değişir:
        // `getallheaders()` istemcinin yazdığı biçimi verir, `$_SERVER['HTTP_*']`
        // fallback'i ise `Ucwords-Tire` biçimine normalize eder.
        //
        // Doğrudan `$this->headers[$key]` araması bu yüzden HTTP/2 üzerinden
        // gelen `authorization` başlığını ISKALARDI — yani tüm kimlik
        // doğrulaması protokol sürümüne göre sessizce çalışır/çalışmazdı.
        if (isset($this->headers[$key])) {
            return $this->headers[$key];
        }

        foreach ($this->headers as $name => $value) {
            if (strcasecmp((string) $name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }


    /**
     * Tüm input verisini döndürür.
     */
    public function all(): array
    {
        return $this->request;
    }


    /**
     * Belirli bir input key var mı?
     */
    public function has(string $key): bool
    {
        return isset($this->request[$key]) && $this->request[$key] !== '';
    }


    /**
     * Input'tan sadece belirtilen key'leri döndürür.
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->request, array_flip($keys));
    }


    /**
     * Input'tan belirtilen key'ler hariç tüm veriyi döndürür.
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->request, array_flip($keys));
    }


    /**
     * JSON body içeriğini döndürür.
     */
    public function json(): array
    {
        return $this->parseJsonBody();
    }


    /**
     * Middleware tarafindan dogrulanan kullanici UUID'sini ayarlar.
     */
    public function setUserUuid(string $uuid): void
    {
        $this->userUuid = $uuid;
    }


    /**
     * Middleware tarafindan dogrulanan kullanici UUID'sini doner.
     * Auth middleware calismadiysa null.
     */
    public function userUuid(): ?string
    {
        return $this->userUuid;
    }


    /**
     * Auth middleware tarafindan dogrulanan kullanici payload'unu (token'daki
     * `user` nesnesi) request'e yazar. Tek auth-state kaynagi: enjekte edilebilen
     * $_REQUEST global'i yerine request-scoped bu alan kullanilir.
     *
     * @param array<string, mixed> $user
     */
    public function setAuthUser(array $user): void
    {
        $this->authUser = $user;
    }


    /**
     * Dogrulanmis kullanici payload'u. Auth middleware calismadiysa null.
     *
     * @return array<string, mixed>|null
     */
    public function authUser(): ?array
    {
        return $this->authUser;
    }


    /**
     * Authorization: Bearer <token> formatı ile gönderilen token'ı döndürür.
     */
    /**
     * `Authorization: Bearer <token>` başlığındaki token.
     *
     * Bearer token'a erişimin TEK kaynağı budur. `AuthService::bearer()`
     * eskiden aynı işi kendi başına, çıplak `getallheaders()` ile yapıyordu;
     * bunun iki sonucu vardı: (1) `AuthService` singleton olmasına rağmen
     * istek-başına global durum okuyordu ve bağımlılık imzasında görünmediği
     * için `ScopeValidator` bunu YAKALAYAMIYORDU, (2) header erişimi iki ayrı
     * yerde, iki farklı sağlamlık seviyesiyle uygulanmıştı.
     *
     * `REDIRECT_HTTP_AUTHORIZATION` fallback'i: Apache + CGI/FastCGI altında
     * Authorization başlığı PHP'ye iletilmez; projenin `.htaccess` dosyası
     * `RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]` ile onu yeniden yazar ve
     * bu da `$_SERVER`'a `REDIRECT_` önekiyle düşer. O workaround olmadan
     * kimlik doğrulama bazı barındırma yapılandırmalarında sessizce çalışmaz.
     */
    public function token(): string|null
    {
        $header = $this->header('Authorization')
            ?? $this->server('REDIRECT_HTTP_AUTHORIZATION')
            ?? '';

        // `Bearer` şeması harf-duyarsızdır (RFC 6750 §2.1 / RFC 9110 §11.1).
        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }


    /**
     * İstemcinin IP adresini döndürür.
     *
     * Güvenlik: Sadece güvenilir proxy'lerden gelen X-Forwarded-For header'ına güvenir.
     * Bu IP spoofing saldırılarını önler.
     */
    public function ip(): string|null
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? null;

        // Remote address yoksa null dön
        if ($remoteAddr === null) {
            return null;
        }

        // Güvenilir proxy kontrolü
        if ($this->isTrustedProxy($remoteAddr)) {
            // X-Forwarded-For header'ını kontrol et
            $forwarded = $this->server['HTTP_X_FORWARDED_FOR'] ?? null;

            if ($forwarded !== null && $forwarded !== '') {
                // İlk IP'yi al (client IP)
                $ips = array_map('trim', explode(',', $forwarded));
                $clientIp = $ips[0];

                // IP formatını doğrula
                if ($this->isValidIp($clientIp)) {
                    return $clientIp;
                }
            }

            // X-Real-IP header'ını kontrol et (nginx)
            $realIp = $this->server['HTTP_X_REAL_IP'] ?? null;
            if ($realIp !== null && $this->isValidIp($realIp)) {
                return $realIp;
            }
        }

        // Varsayılan: REMOTE_ADDR
        return $this->isValidIp($remoteAddr) ? $remoteAddr : null;
    }

    /**
     * IP adresinin geçerli olup olmadığını kontrol et
     */
    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Verilen IP'nin güvenilir proxy listesinde olup olmadığını kontrol et
     */
    private function isTrustedProxy(string $ip): bool
    {
        $proxies = $this->trustedProxies;

        foreach ($proxies as $proxy) {
            // CIDR notation kontrolü (örn: 10.0.0.0/8)
            if (str_contains($proxy, '/')) {
                if ($this->ipInCidr($ip, $proxy)) {
                    return true;
                }
            } elseif ($ip === $proxy) {
                return true;
            }
        }

        return false;
    }

    /**
     * IP'nin CIDR aralığında olup olmadığını kontrol et
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;

        // IPv4 kontrolü
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $maskLong = -1 << (32 - $mask);
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        return false;
    }

    /**
     * Güvenilir proxy listesini ayarla
     *
     * @param array<string> $proxies
     */
    public function setTrustedProxies(array $proxies): void
    {
        $this->trustedProxies = $proxies;
    }


    /**
     * Request path'ini döndürür (query string hariç, yüzde-kodlaması çözülmüş).
     *
     * - Query string ayrılır (`?` sonrası atılır).
     * - rawurldecode ile yüzde-kodlaması çözülür (ör. `/users/john%20doe`).
     *   rawurldecode kullanılır çünkü path segment'lerinde `+` literaldir
     *   (urldecode onu boşluğa çevirirdi).
     *
     * Not: `%2F` çözüldüğünde gerçek `/`'a döner; bu segment sayısını değiştirir.
     * Taban yolu ayıklaması (front-controller önekini silme) Router'a aittir.
     */
    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';

        $position = strpos($uri, '?');
        if ($position !== false) {
            $uri = substr($uri, 0, $position);
        }

        $uri = rawurldecode($uri);

        return $uri === '' ? '/' : $uri;
    }


    /**
     * HTTP metodunu döndürür (GET, POST, PUT, PATCH, DELETE, vb.).
     */
    public function method(): string
    {
        return $this->server['REQUEST_METHOD'] ?? 'GET';
    }


    /**
     * $_GET içinden belirli bir değer döndürür.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($_GET)[$key] ?? $default;
    }


    /**
     * $_GET içinden belirli bir değer döndürür (query alias).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query($key, $default);
    }


    /**
     * POST request body'sinden belirli bir değer döndürür.
     * $_POST ve JSON body birleştirilir (JSON öncelikli).
     */
    public function post(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'POST') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }


    /**
     * PUT request body'sinden belirli bir değer döndürür.
     * $_POST ve JSON body birleştirilir (JSON öncelikli).
     */
    public function put(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'PUT') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }


    /**
     * PATCH request body'sinden belirli bir değer döndürür.
     * $_POST ve JSON body birleştirilir (JSON öncelikli).
     */
    public function patch(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'PATCH') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }


    /**
     * DELETE request body'sinden belirli bir değer döndürür.
     * $_POST ve JSON body birleştirilir (JSON öncelikli).
     */
    public function delete(string $key, mixed $default = null): mixed
    {
        if ($this->method() !== 'DELETE') {
            return $default;
        }

        $data = [...$this->sanitize($_POST), ...$this->parseJsonBody()];

        return $data[$key] ?? $default;
    }


    /**
     * Tüm HTTP header'larını alır.
     * - getallheaders() varsa onu kullanır.
     * - Yoksa manuel olarak $_SERVER üzerinden üretir.
     */
    private function parseHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('_', '-', substr($key, 5));
                $header = ucwords(strtolower($header), '-');
                $headers[$header] = $value;
            }
        }

        return $headers;
    }


    /**
     * php://input içindeki JSON body'i parse eder.
     * - Bir kez okur (cache)
     * - JSON_THROW_ON_ERROR ile güvenli decode
     * - Hatalı JSON'da boş array döner
     */
    private function parseJsonBody(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $content = file_get_contents('php://input');

        if ($content === '' || $content === false) {
            return $this->jsonCache = [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            return $this->jsonCache = is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return $this->jsonCache = [];
        }
    }


    /**
     * Input sanitize:
     * - Array ise recursive sanitize
     * - String ise trim + UTF-8 normalize + control character temizleme
     * - null → boş string
     * - Diğer tipler dokunulmadan döner
     *
     * Güvenlik kontrolleri:
     * - Null byte injection koruması
     * - UTF-8 encoding doğrulama
     * - Control character temizleme (tab/newline hariç)
     * - Maksimum string uzunluğu kontrolü
     */
    private function sanitize(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Key'i de sanitize et
            $safeKey = $this->sanitizeKey($key);

            if (is_array($value)) {
                $result[$safeKey] = $this->sanitize($value);

            } elseif (is_string($value)) {
                $result[$safeKey] = $this->sanitizeString($value);

            } elseif ($value === null) {
                $result[$safeKey] = '';

            } else {
                $result[$safeKey] = $value;
            }
        }

        return $result;
    }

    /**
     * String değeri sanitize et
     */
    private function sanitizeString(string $value): string
    {
        // 1. Trim
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Fast path (yaygın durum): değer zaten geçerli UTF-8 ve kontrol karakteri
        // içermiyorsa pahalı temizlemeyi (mb_convert + iki regex replace) atla.
        // preg_match: 0 = eşleşme yok (temiz), 1 = kontrol karakteri var,
        // false = geçersiz UTF-8 (her ikisinde de tam temizleme yapılır).
        $hasControlOrInvalid = preg_match(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{0080}-\x{009F}]/u',
            $value
        );
        if ($hasControlOrInvalid === 0) {
            return $value;
        }

        // 2. Null byte temizleme (injection koruması)
        $value = str_replace("\0", '', $value);

        // 3. UTF-8 encoding doğrulama ve düzeltme
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        // 4. Control karakterleri temizle (tab \x09, newline \x0A, carriage return \x0D hariç)
        // ASCII 0-8, 11-12, 14-31, 127 kontrol karakterleridir
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // 5. UTF-8 control karakterleri temizle (C0, C1 control codes)
        $value = preg_replace('/[\x{0080}-\x{009F}]/u', '', $value);

        return $value;
    }

    /**
     * Array key'ini sanitize et
     */
    private function sanitizeKey(string|int $key): string|int
    {
        if (is_int($key)) {
            return $key;
        }

        // Null byte ve control karakterlerini temizle
        $key = str_replace("\0", '', $key);
        $key = preg_replace('/[\x00-\x1F\x7F]/', '', $key);

        return $key;
    }
}
