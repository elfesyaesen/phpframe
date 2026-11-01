<?php

declare(strict_types=1);

namespace Tests\Functional\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Functional\FunctionalTestCase;

#[Group('functional')]
final class ErrorEnvelopeTest extends FunctionalTestCase
{
    /**
     * @return array<string, array{string, string, array<string, mixed>|null, int}>
     */
    public static function errorPathProvider(): array
    {
        return [
            'router: bilinmeyen yol → 404' => [
                'GET', '/v1/api/olmayan-yol', null, 404,
            ],
            'router: yanlış metot → 405' => [
                'DELETE', '/v1/api/auth/login', null, 405,
            ],

            'auth middleware: token yok → 401' => [
                'GET', '/v1/api/auth/me', null, 401,
            ],
            'auth middleware: korumalı profil → 401' => [
                'GET', '/v1/api/users/me', null, 401,
            ],
            'auth middleware: admin yolu → 401' => [
                'GET', '/v1/api/admin/roles', null, 401,
            ],

            'validator: eksik alanlar → 422' => [
                'POST', '/v1/api/users/register', ['username' => 'a'], 422,
            ],

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

    public function testMalformedTokenProducesSameEnvelope(): void
    {
        $response = $this->get('/v1/api/auth/me', 'gecersiz.token.degeri');

        $this->assertErrorEnvelope($response, 401);
    }

    public function testValidationErrorCarriesPerFieldMessages(): void
    {
        $response = $this->post('/v1/api/users/register', ['username' => 'a']);

        $this->assertErrorEnvelope($response, 422);

        $body = $response['body'];

        self::assertIsArray($body);
        self::assertArrayHasKey('errors', $body['error']);
        self::assertIsArray($body['error']['errors']);
        self::assertNotEmpty($body['error']['errors']);

        foreach ($body['error']['errors'] as $field => $messages) {
            self::assertIsString($field);
            self::assertIsArray($messages, "Alan '$field' için mesajlar dizi olmalı.");
            self::assertNotEmpty($messages);
        }
    }

    public function testJsonMessagesAreNotHtmlEscaped(): void
    {
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

    public function testDebugDetailsAreAbsentInProduction(): void
    {
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
