<?php

declare(strict_types=1);

namespace Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Group;
use Tests\Functional\FunctionalTestCase;

/**
 * `Response` SÖZLEŞMESİ — durum kodları, gövde biçimi ve header'lar.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * BU SUITE, CONTROLLER'LARIN `Response` DÖNDÜRMEYE GEÇİŞİNİN AĞIDIR.
 *
 * O refactor `Router`, `Response`, 21 action ve tüm middleware'i
 * değiştirecek. Değişmemesi gereken şey burada yazılı: dışarıdan
 * gözlemlenebilir HTTP davranışı.
 *
 * Buradaki testler kasten "gövde alanı" değil "protokol" seviyesinde:
 * durum kodu, `Content-Type`, gövdenin VARLIĞI/YOKLUĞU ve header'lar.
 * Bunlar refactor'un kırabileceği ve tip sisteminin yakalayamayacağı
 * şeylerdir.
 *
 * Beklentiler VARSAYILMADI, canlı sunucudan ÖLÇÜLDÜ. Ölçümün mevcut
 * davranışı belgeleyen (ve tartışmalı olan) noktaları ilgili testin
 * yorumunda işaretlidir.
 * ─────────────────────────────────────────────────────────────────────────
 */
#[Group('functional')]
final class ResponseSemanticsTest extends FunctionalTestCase
{
    // ── JSON yanıt header'ları ────────────────────────────────────

    /**
     * `Response::jsonResponse()` doğru `Content-Type` göndermeli.
     *
     * Kimlikli bir uç kullanılıyor: `GET /v1/api` KULLANILAMAZ, çünkü
     * `HomeController::index()` `Response` katmanına hiç girmiyor —
     * `public/swagger.php`'yi doğrudan `require` edip HTML basıyor
     * (`text/html`, header yok). Bu suite `Response`'u test ediyor,
     * dolayısıyla ondan geçen bir yanıt gerekiyor.
     */
    public function testJsonResponseSendsJsonContentType(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertSame(200, $response['status'], 'Gövde: ' . $response['raw']);
        $this->assertJsonContentType($response);
        self::assertIsArray($response['body']);
    }

    /**
     * Güvenlik header'ları JSON yanıtlarda olmalı.
     *
     * `Response::sendSecurityHeaders()` tek noktadan uyguluyor; refactor
     * sırasında bir yol o çağrıyı atlarsa burası yakalar.
     */
    public function testSecurityHeadersArePresentOnJsonResponses(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertArrayHasKey('x-content-type-options', $response['headers']);
        self::assertSame('nosniff', $response['headers']['x-content-type-options']);
    }

    /**
     * Kimlikli yanıtlar cache'lenmemeli.
     *
     * Ara katmanlarda cache'lenen kimlikli JSON, bir kullanıcının
     * verisinin başkasına servis edilmesi demektir.
     */
    public function testAuthenticatedResponsesAreNotCacheable(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertArrayHasKey('cache-control', $response['headers']);
        self::assertStringContainsString('no-store', $response['headers']['cache-control']);
        self::assertArrayHasKey('pragma', $response['headers']);
    }

    /**
     * Hata yanıtları da güvenlik header'larını taşımalı.
     *
     * Ayrı test: hata yolu `ExceptionHandler` üzerinden geçer, başarı
     * yolundan farklı bir kod yoludur.
     */
    public function testErrorResponsesAlsoCarrySecurityHeaders(): void
    {
        $response = $this->get('/v1/api/users/me');   // token yok → 401

        self::assertSame(401, $response['status']);
        $this->assertJsonContentType($response);
        self::assertArrayHasKey('x-content-type-options', $response['headers']);
        self::assertStringContainsString('no-store', $response['headers']['cache-control'] ?? '');
    }

    /**
     * `Content-Language` BAŞARI yanıtlarında gönderiliyor.
     *
     * ─────────────────────────────────────────────────────────────────────
     * ÖLÇÜLEN DAVRANIŞ: başarı yolunda `Content-Language: tr` var, HATA
     * yolunda YOK. Bu bir tutarsızlıktır ama tesadüf değil:
     * `Response::sendContentLanguageHeader()` aktif locale'i container'dan
     * okur, `ExceptionHandler` ise bootstrap'ta kaydedilen bir SINGLETON
     * ve locale'i yoktur (ona scoped `Translator` vermek `ScopeValidator`'ın
     * reddettiği Singleton→Scoped kenarını yaratırdı).
     *
     * Test bu yüzden yalnızca başarı yolunu doğruluyor. Hata yolundaki
     * eksiklik bilinen ve gerekçelendirilmiş bir sınırdır; burada
     * assert EDİLMEZ ki refactor onu düzeltirse test sahte kırmızı vermesin.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function testContentLanguageIsSentOnSuccessResponses(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertArrayHasKey('content-language', $response['headers']);
        self::assertNotSame('', $response['headers']['content-language']);
    }

    // ── Gövde bütünlüğü ───────────────────────────────────────────

    /**
     * Gövde TEK bir JSON dokümanı olmalı.
     *
     * ─────────────────────────────────────────────────────────────────────
     * `exit` kaldırılırken kırılma riski taşıyan invariant. Bir action
     * yanıt yazdıktan sonra `return` etmezse ikinci bir gövde eklenir ve
     * istemci JSON ayrıştırma hatası alır.
     *
     * `markSent()` guard'ı çift `jsonResponse()` çağrısını
     * `LogicException`'a çeviriyor, ama gövde SONRASI düz `echo`'yu
     * engellemez. Çift gönderimin en görünür belirtisi bitişik `}{`'dir.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function testBodyIsASingleJsonDocument(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertStringNotContainsString('}{', $response['raw'], 'Gövdede iki JSON dokümanı var.');
        self::assertNotNull(
            json_decode($response['raw'], true),
            'Gövde geçerli tek bir JSON değil: ' . $response['raw']
        );
    }

    /**
     * `Content-Length` gövdeyle tutarlı olmalı.
     *
     * PHP'nin dahili sunucusu chunked kullandığı için header genelde yok;
     * o durumda test kendini atlar. Nginx/Apache altında koşturulduğunda
     * gerçek bir çift-gönderim kapısı olur.
     */
    public function testContentLengthMatchesBodyWhenSent(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        if (!isset($response['headers']['content-length'])) {
            self::markTestSkipped('Sunucu Content-Length göndermiyor (chunked).');
        }

        self::assertSame(
            strlen($response['raw']),
            (int) $response['headers']['content-length'],
            'Content-Length gövde uzunluğuyla uyuşmuyor — çift gönderim olabilir.'
        );
    }

    // ── Router semantiği ──────────────────────────────────────────

    public function testUnknownRouteReturns404(): void
    {
        self::assertSame(404, $this->get('/v1/api/olmayan-yol')['status']);
    }

    public function testWrongMethodReturns405(): void
    {
        self::assertSame(405, $this->delete('/v1/api/auth/login')['status']);
    }

    /**
     * CORS preflight gövdesiz 204 dönmeli.
     *
     * `Response` refactor'ında gözden kaçması kolay bir yol: preflight
     * `Router::dispatch()` içinde, controller'a HİÇ GİRMEDEN yanıtlanıyor.
     * Kod tabanındaki tek 204 üreten yol da budur.
     */
    public function testCorsPreflightReturnsEmpty204(): void
    {
        $response = $this->request('OPTIONS', '/v1/api/auth/login');

        self::assertSame(204, $response['status']);
        self::assertSame('', trim($response['raw']), '204 yanıtı gövde taşımamalı.');
    }

    /**
     * `HomeController` `Response` katmanını ATLIYOR — mevcut davranışın
     * belgelenmesi.
     *
     * ─────────────────────────────────────────────────────────────────────
     * `HomeController::index()`'in tüm gövdesi
     * `require_once APP_ROOT . '/public/swagger.php'`. Sonucu ölçüldü:
     * `text/html`, ve `X-Content-Type-Options` / `Cache-Control` YOK —
     * çünkü `Response::sendSecurityHeaders()` hiç çağrılmıyor.
     *
     * Bu test o davranışı SABİTLEMEK için değil, GÖRÜNÜR kılmak için var:
     * Swagger arayüzü güvenlik header'ları olmadan servis ediliyor.
     * Düzeltilecekse doğru biçim `htmlResponse()` üzerinden geçmektir; o
     * zaman bu testin beklentisi bilinçli olarak güncellenir.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function testHomeControllerBypassesResponseLayer(): void
    {
        $response = $this->get('/v1/api');

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('text/html', $response['headers']['content-type'] ?? '');
        self::assertArrayNotHasKey(
            'x-content-type-options',
            $response['headers'],
            'HomeController artık Response üzerinden geçiyor — bu testi güncelleyin.'
        );
    }

    // ── Kimlikli başarı zarfları ──────────────────────────────────

    public function testLoginReturnsTokenBundleShape(): void
    {
        $response = $this->post('/v1/api/auth/login', $this->credentials());

        self::assertSame(200, $response['status']);
        $this->assertJsonContentType($response);

        $body = $response['body'];
        self::assertIsArray($body);

        foreach (['access_token', 'refresh_token', 'expired_at', 'user'] as $key) {
            self::assertArrayHasKey($key, $body, "TokenBundle anahtarı eksik: $key");
        }

        self::assertIsArray($body['user']);
        self::assertArrayHasKey('uuid', $body['user']);

        // Parola hash'i ASLA sızmamalı.
        self::assertArrayNotHasKey('password', $body['user']);
    }

    public function testProfileEndpointNeverLeaksPasswordHash(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertSame(200, $response['status']);
        self::assertIsArray($response['body']);
        self::assertArrayHasKey('uuid', $response['body']);
        self::assertArrayNotHasKey('password', $response['body']);
    }

    public function testAdminRoleListingReturnsArray(): void
    {
        $response = $this->get('/v1/api/admin/roles', $this->accessToken());

        self::assertSame(200, $response['status'], 'Gövde: ' . $response['raw']);
        self::assertIsArray($response['body']);
    }

    /**
     * Avatar yokken silme 404 dönüyor — mevcut davranışın belgelenmesi.
     *
     * ─────────────────────────────────────────────────────────────────────
     * ÖLÇÜLDÜ: 404 (204 değil). HTTP semantiği açısından tartışmalı —
     * DELETE idempotent olmalıdır ve "zaten yok" durumu genelde 204 ile
     * karşılanır. Ama bu KIRICI bir değişiklik olur ve mevcut istemciler
     * 404 bekliyor.
     *
     * Test mevcut sözleşmeyi kilitliyor: `Response` refactor'ı bunu
     * KAZAYLA değiştirmemeli. Bilinçli değiştirilecekse burası da
     * bilinçli güncellenir.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function testDeletingMissingAvatarReturns404(): void
    {
        $response = $this->delete('/v1/api/users/me/avatar', $this->accessToken());

        $this->assertErrorEnvelope($response, 404);
    }
}
