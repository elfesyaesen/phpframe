<?php

declare(strict_types=1);

namespace Tests\Functional\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Functional\FunctionalTestCase;

/**
 * Hata zarfı SÖZLEŞMESİ — reddeden katman ne olursa olsun aynı biçim.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * BU SUITE'İN VAR OLMA SEBEBİ:
 *
 * API bir zamanlar REDDEDEN KATMANA GÖRE iki farklı hata biçimi
 * döndürüyordu. `RoleMiddleware`/`PermissionMiddleware` exception fırlatıp
 * `{"error":{...}}` üretirken, `AuthMiddleware::unauthorized()` ve tüm
 * controller'lar düz `{"message":"..."}` üretiyordu. İstemci, hangi
 * katmanın reddettiğine göre farklı bir gövde okumak zorundaydı.
 *
 * Zarf birleştirildi. Bu testler o birleşmenin KORUYUCUSUDUR: aşağıdaki
 * her satır farklı bir katmandan geçer (router, middleware, validator,
 * servis) ve hepsinin AYNI zarfı üretmesi gerekir.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Ayrıca `Response` dönüşüne geçiş için birincil regresyon ağıdır: o
 * refactor Router, Response, 21 action ve tüm middleware'i değiştirecek.
 * Değişmemesi gereken şey tam olarak burada yazılı.
 */
#[Group('functional')]
final class ErrorEnvelopeTest extends FunctionalTestCase
{
    /**
     * Her satır FARKLI bir reddetme katmanından geçer.
     *
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    public static function errorPathProvider(): array
    {
        return [
            // Router katmanı
            'router: bilinmeyen yol → 404' => [
                'GET', '/v1/api/olmayan-yol', null, 404,
            ],
            'router: yanlış metot → 405' => [
                'DELETE', '/v1/api/auth/login', null, 405,
            ],

            // AuthMiddleware katmanı — eskiden düz `message` döndürüyordu
            'auth middleware: token yok → 401' => [
                'GET', '/v1/api/auth/me', null, 401,
            ],
            'auth middleware: korumalı profil → 401' => [
                'GET', '/v1/api/users/me', null, 401,
            ],
            'auth middleware: admin yolu → 401' => [
                'GET', '/v1/api/admin/roles', null, 401,
            ],

            // Validator katmanı
            'validator: eksik alanlar → 422' => [
                'POST', '/v1/api/users/register', ['username' => 'a'], 422,
            ],

            // Servis katmanı
            'servis: geçersiz sıfırlama token → 404' => [
                'POST', '/v1/api/users/reset-password/confirm',
                [
                    'token'                 => str_repeat('a', 64),
                    'password'              => 'Yeni12345',
                    'password_confirmation' => 'Yeni12345',
                ],
                404,
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('errorPathProvider')]
    public function testAllRejectionLayersProduceTheSameEnvelope(
        string $method,
        string $path,
        ?array $body,
        int $expectedStatus,
    ): void {
        $response = $this->request($method, $path, $body);

        $this->assertErrorEnvelope($response, $expectedStatus);
        $this->assertJsonContentType($response);
    }

    /**
     * Bozuk token da AYNI zarfı üretmeli.
     *
     * Ayrı test: JWT ayrıştırma hatası farklı bir kod yolundan geçer.
     */
    public function testMalformedTokenProducesSameEnvelope(): void
    {
        $response = $this->get('/v1/api/auth/me', 'gecersiz.token.degeri');

        $this->assertErrorEnvelope($response, 401);
    }

    /**
     * Doğrulama hatası ALAN BAŞINA hata listesi taşımalı.
     *
     * `error.errors` yapısı istemci tarafında form alanlarını işaretlemek
     * için kullanılır; kaybolması sessiz bir UX regresyonu olurdu.
     */
    public function testValidationErrorCarriesPerFieldMessages(): void
    {
        $response = $this->post('/v1/api/users/register', ['username' => 'a']);

        $this->assertErrorEnvelope($response, 422);

        $body = $response['body'];

        self::assertIsArray($body);
        self::assertArrayHasKey('errors', $body['error']);
        self::assertIsArray($body['error']['errors']);
        self::assertNotEmpty($body['error']['errors']);

        // Her alan, MESAJ DİZİSİ taşımalı (tek string değil).
        foreach ($body['error']['errors'] as $field => $messages) {
            self::assertIsString($field);
            self::assertIsArray($messages, "Alan '$field' için mesajlar dizi olmalı.");
            self::assertNotEmpty($messages);
        }
    }

    /**
     * JSON gövdesine HTML KAÇIŞI UYGULANMAMALI.
     *
     * ─────────────────────────────────────────────────────────────────────
     * Düzeltilmiş bir hatanın regresyon koruması. `HttpException::toArray()`
     * mesajı `htmlspecialchars`'tan geçiriyordu:
     *
     *   girdi   Kullanıcı'nın kaydı & bulunamadı
     *   çıktı   Kullanıcı&apos;nın kaydı &amp; bulunamadı
     *
     * Gövde `application/json` olarak yayınlanıyor; `json_encode` zaten
     * bağlamına uygun kaçış yapar. HTML kaçışı orada yalnızca VERİYİ
     * BOZUYORDU ve Türkçe mesajlarda kesme işareti çok yaygın olduğu için
     * neredeyse her mesajı etkiliyordu.
     * ─────────────────────────────────────────────────────────────────────
     */
    public function testJsonMessagesAreNotHtmlEscaped(): void
    {
        // 401 mesajı Türkçe ve kesme işareti/ampersand içerebilir.
        $response = $this->get('/v1/api/users/me');

        $this->assertErrorEnvelope($response, 401);

        foreach (['&amp;', '&apos;', '&quot;', '&#039;', '&lt;', '&gt;'] as $entity) {
            self::assertStringNotContainsString(
                $entity,
                $response['raw'],
                "JSON gövdesinde HTML entity var ($entity) — htmlspecialchars regresyonu."
            );
        }
    }

    /**
     * Production'da `error.debug` SIZDIRILMAMALI.
     *
     * Dev ortamında bulunması beklenir; bu test yalnızca APP_PRODUCTION
     * true iken anlamlıdır ve aksi halde kendini atlar. Böylece production
     * yapılandırmasında koşturulduğunda gerçek bir kapı olur.
     */
    public function testDebugDetailsAreAbsentInProduction(): void
    {
        // `Env::get()` DEĞİL, `EnvReader::bool()`.
        //
        // Bu ayrım bu testi bir kez yanlış çalıştırdı ve gerçek bir tuzağı
        // ortaya çıkardı: `.env` içinde `APP_PRODUCTION=fale` (yazım hatası)
        // yazıyordu. `Env::get()` ham değeri döner — `'fale'` PHP'de
        // TRUTHY'dir. `EnvReader::bool()` ise katı bir allowlist uygular
        // (`1|true|yes|on`) ve `false` döner. Framework `EnvReader`
        // kullandığı için sunucu development modundaydı; test ise
        // production sanıp `debug` yokluğunu doğrulamaya çalıştı.
        //
        // Kural: boolean ortam değerini HER ZAMAN framework'ün okuduğu gibi
        // oku, yoksa test ile uygulama farklı gerçeklerde yaşar.
        if (!(new \System\Config\EnvReader())->bool('APP_PRODUCTION', false)) {
            self::markTestSkipped('Yalnızca APP_PRODUCTION=true iken anlamlı.');
        }

        $response = $this->get('/v1/api/users/me');

        self::assertIsArray($response['body']);
        self::assertArrayNotHasKey(
            'debug',
            $response['body']['error'],
            'Production yanıtında stack trace sızıyor.'
        );
    }
}
