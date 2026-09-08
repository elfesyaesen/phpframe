<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;
use Redis;
use System\Config\RedisConfig;
use Throwable;

/**
 * HTTP seviyesi testler için taban sınıf.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN CANLI SUNUCUYA İSTEK ATIYOR, KERNEL'İ İÇERİDEN ÇAĞIRMIYOR:
 *
 * `Response` çıktıyı `header()` + `echo` ile DOĞRUDAN global çıktı akışına
 * yazıyor (16 çağrı). Kernel'i test süreci içinde dispatch etmek
 * `ob_start()` gerektirir ve header'lar CLI'da hiç doğrulanamaz — yani
 * durum kodunu ve `Content-Type`'ı test edemezdik, ki bu testlerin asıl
 * konusu tam olarak onlar.
 *
 * Bu bir tasarım ödünüdür ve GEÇİCİDİR: controller'lar `Response`
 * DÖNDÜRMEYE geçtiğinde (bkz. README "Her yanıt return ile biter"
 * tartışması) bu suite süreç-içi çalışabilir hale gelir. O geçişin
 * regresyon ağı da tam olarak bu suite'tir.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * ── SIRALAMA BAĞIMLILIĞI YOK — bilinçli ─────────────────────────────────
 *
 * Bu suite'in yerini aldığı bash betiği KATI BİR SIRA gerektiriyordu:
 * `users_token` kullanıcı başına tek satır tuttuğu için her login öncekini
 * öldürüyor, refresh rotasyon yapıyor ve login rate limit'i 5/60.
 *
 * Burada her test KENDİ oturumunu açar ve `setUp()` rate limiter'ı
 * temizler. Böylece testler herhangi bir sırada, tek tek veya paralel
 * koşabilir. PHPUnit'in `executionOrder` ayarı testleri yeniden
 * sıralayabildiği için bu bir gereklilikti, kolaylık değil.
 * ─────────────────────────────────────────────────────────────────────────
 */
abstract class FunctionalTestCase extends TestCase
{
    protected static string $baseUrl = '';

    /**
     * Sunucu erişilebilirliği süreç başına BİR KEZ ölçülür.
     *
     * `null` = henüz ölçülmedi.
     */
    private static ?bool $serverUp = null;

    protected function setUp(): void
    {
        self::$baseUrl = rtrim((string) (getenv('PHPFRAME_TEST_URL') ?: ''), '/');

        if (self::$baseUrl === '') {
            self::markTestSkipped('PHPFRAME_TEST_URL tanımlı değil.');
        }

        if (!$this->serverIsUp()) {
            self::markTestSkipped(sprintf(
                'HTTP sunucusu erişilemez: %s — başlatmak için: php frame serve --port=%d',
                self::$baseUrl,
                (int) (parse_url(self::$baseUrl, PHP_URL_PORT) ?: 80)
            ));
        }

        $this->clearRateLimits();
    }

    // ── Ortam ─────────────────────────────────────────────────────

    private function serverIsUp(): bool
    {
        if (self::$serverUp !== null) {
            return self::$serverUp;
        }

        $ch = curl_init(self::$baseUrl . '/v1/api');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        curl_exec($ch);
        $ok = curl_errno($ch) === 0;
        curl_close($ch);

        return self::$serverUp = $ok;
    }

    /**
     * Rate limiter'ı temizler — testlerin tekrar tekrar koşabilmesi için.
     *
     * ─────────────────────────────────────────────────────────────────────
     * `RateLimiter::clearAll()` BURADA KULLANILAMAZ.
     *
     * O metot yalnızca DOSYA tabanlı (`*.rate`) limitleri siler;
     * `CACHE_DRIVER=redis` iken sessizce HİÇBİR ŞEY yapmaz. Bu bir
     * framework hatasıdır ve düzeltilene kadar Redis anahtarları burada
     * doğrudan silinir. Düzeltildiğinde bu metot `clearAll()` çağrısına
     * indirgenebilir.
     *
     * Gerekli olmasının sebebi somut: `/users/register` ve
     * `/users/reset-password` limitleri 3/3600, yani temizlenmezse suite
     * saatte yalnızca üç kez koşabilirdi.
     * ─────────────────────────────────────────────────────────────────────
     */
    protected function clearRateLimits(): void
    {
        // `Kernel::create()` KULLANILMAZ — bilinçli.
        //
        // Kernel boot'u `ErrorHandlingProvider` üzerinden global hata ve
        // exception yöneticileri kaydeder ve onları geri almaz. PHPUnit
        // bunu haklı olarak "risky test" sayar (`failOnRisky=true`), çünkü
        // bir test süreç genelinde iz bırakmış olur. Redis ayarını
        // container'a hiç ihtiyaç duymadan `EnvReader` ile okumak aynı
        // değerleri verir ve hiçbir yan etki üretmez.
        try {
            $config = RedisConfig::fromEnv(new \System\Config\EnvReader());

            $redis = new Redis();
            $redis->connect($config->host, $config->port, 2.0);

            if ($config->password !== '') {
                $redis->auth($config->password);
            }

            $redis->select($config->database);

            $keys = $redis->keys('*:rate:*');

            if ($keys !== []) {
                $redis->del($keys);
            }
        } catch (Throwable) {
            // Redis yoksa dosya fallback'i kısa ömürlüdür; testi bu yüzden
            // başarısız saymıyoruz.
        }

        foreach (glob(APP_ROOT . '/system/cache/rate/*.rate') ?: [] as $file) {
            @unlink($file);
        }
    }

    // ── HTTP yardımcıları ─────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>}
     */
    protected function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $token = null,
    ): array {
        $headers = ['Accept: application/json'];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init(self::$baseUrl . $path);

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,   // 302'yi TESTİN görmesi gerekir
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $options);

        $response   = (string) curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $raw        = substr($response, $headerSize);

        return [
            'status'  => $status,
            'body'    => json_decode($raw, true),
            'raw'     => $raw,
            'headers' => $this->parseHeaders($rawHeaders),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $headers = [];

        foreach (explode("\r\n", $raw) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    /** @param array<string, mixed>|null $body @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>} */
    protected function get(string $path, ?string $token = null): array
    {
        return $this->request('GET', $path, null, $token);
    }

    /** @param array<string, mixed>|null $body @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>} */
    protected function post(string $path, ?array $body = null, ?string $token = null): array
    {
        return $this->request('POST', $path, $body, $token);
    }

    /** @param array<string, mixed>|null $body @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>} */
    protected function put(string $path, ?array $body = null, ?string $token = null): array
    {
        return $this->request('PUT', $path, $body, $token);
    }

    /** @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>} */
    protected function delete(string $path, ?string $token = null): array
    {
        return $this->request('DELETE', $path, null, $token);
    }

    // ── Kimlik ────────────────────────────────────────────────────

    /**
     * Test kullanıcısının kimlik bilgileri — ORTAMDAN okunur.
     *
     * ─────────────────────────────────────────────────────────────────────
     * KODA GÖMÜLMEZ. Bu depo halka açıktır; bir test dosyasına yazılan
     * "örnek" parola, o parolayı kullanan her kurulum için gerçek bir
     * kimlik bilgisidir. Ayrıca gömülü kimlik bilgisi, `seed_rbac`
     * migration'ının bilinçli olarak admin kullanıcısı YARATMAMA kararıyla
     * da çelişirdi.
     *
     * Tanımlı değilse kimlik gerektiren testler ATLANIR.
     *
     *   PHPFRAME_TEST_EMAIL=... PHPFRAME_TEST_PASSWORD=... composer test
     * ─────────────────────────────────────────────────────────────────────
     *
     * @return array{email: string, password: string}
     */
    protected function credentials(): array
    {
        $email    = (string) (getenv('PHPFRAME_TEST_EMAIL') ?: '');
        $password = (string) (getenv('PHPFRAME_TEST_PASSWORD') ?: '');

        if ($email === '' || $password === '') {
            self::markTestSkipped(
                'Kimlik gerektiren test: PHPFRAME_TEST_EMAIL ve '
                . 'PHPFRAME_TEST_PASSWORD ortam değişkenlerini tanımlayın.'
            );
        }

        return ['email' => $email, 'password' => $password];
    }

    /**
     * Yeni bir oturum açar ve token çiftini döner.
     *
     * Her çağrı `users_token` satırını YENİLER, yani önceki access token'ı
     * geçersiz kılar. Testler bu yüzden kendi token'ını alır ve hemen
     * kullanır; token'lar testler arasında PAYLAŞILMAZ.
     *
     * @return array{access: string, refresh: string, user: array<string, mixed>}
     */
    protected function login(): array
    {
        $response = $this->post('/v1/api/auth/login', $this->credentials());

        self::assertSame(
            200,
            $response['status'],
            'Test kullanıcısıyla login başarısız: ' . $response['raw']
        );

        $body = $response['body'];

        self::assertIsArray($body);
        self::assertArrayHasKey('access_token', $body);
        self::assertArrayHasKey('refresh_token', $body);

        return [
            'access'  => (string) $body['access_token'],
            'refresh' => (string) $body['refresh_token'],
            'user'    => is_array($body['user'] ?? null) ? $body['user'] : [],
        ];
    }

    protected function accessToken(): string
    {
        return $this->login()['access'];
    }

    // ── Assertion yardımcıları ────────────────────────────────────

    /**
     * Hata zarfının SÖZLEŞMESİNİ doğrular.
     *
     * Kanonik konum `error.message`; üst seviye `message` bir GEÇİŞ
     * anahtarıdır ve düşürülecektir. İkisini birlikte doğrulamak, geçiş
     * anahtarı kaldırıldığında bu testin bilinçli olarak güncellenmesini
     * zorunlu kılar — sessizce kırılmasını değil.
     *
     * @param array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>} $response
     */
    protected function assertErrorEnvelope(array $response, int $expectedStatus): void
    {
        self::assertSame($expectedStatus, $response['status'], 'Gövde: ' . $response['raw']);

        $body = $response['body'];

        self::assertIsArray($body, 'Hata gövdesi JSON değil: ' . $response['raw']);
        self::assertArrayHasKey('error', $body);
        self::assertIsArray($body['error']);
        self::assertArrayHasKey('code', $body['error']);
        self::assertArrayHasKey('message', $body['error']);
        self::assertSame($expectedStatus, $body['error']['code']);
        self::assertNotSame('', $body['error']['message']);

        // Geçiş anahtarı — kaldırıldığında bu satır kasten kırılır.
        self::assertArrayHasKey('message', $body, 'Geçiş `message` anahtarı kayboldu.');
        self::assertSame($body['error']['message'], $body['message']);
    }

    /**
     * @param array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>} $response
     */
    protected function assertJsonContentType(array $response): void
    {
        self::assertArrayHasKey('content-type', $response['headers']);
        self::assertStringContainsString('application/json', $response['headers']['content-type']);
    }
}
