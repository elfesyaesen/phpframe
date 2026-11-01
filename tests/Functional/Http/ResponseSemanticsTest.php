<?php

declare(strict_types=1);

namespace Tests\Functional\Http;

use PHPUnit\Framework\Attributes\Group;
use Tests\Functional\FunctionalTestCase;

#[Group('functional')]
final class ResponseSemanticsTest extends FunctionalTestCase
{
    public function testJsonResponseSendsJsonContentType(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertSame(200, $response['status'], 'Gövde: ' . $response['raw']);
        $this->assertJsonContentType($response);
        self::assertIsArray($response['body']);
    }

    public function testSecurityHeadersArePresentOnJsonResponses(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertArrayHasKey('x-content-type-options', $response['headers']);
        self::assertSame('nosniff', $response['headers']['x-content-type-options']);
    }

    public function testAuthenticatedResponsesAreNotCacheable(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertArrayHasKey('cache-control', $response['headers']);
        self::assertStringContainsString('no-store', $response['headers']['cache-control']);
        self::assertArrayHasKey('pragma', $response['headers']);
    }

    public function testErrorResponsesAlsoCarrySecurityHeaders(): void
    {
        $response = $this->get('/v1/api/users/me');

        self::assertSame(401, $response['status']);
        $this->assertJsonContentType($response);
        self::assertArrayHasKey('x-content-type-options', $response['headers']);
        self::assertStringContainsString('no-store', $response['headers']['cache-control'] ?? '');
    }

    public function testContentLanguageIsSentOnSuccessResponses(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertArrayHasKey('content-language', $response['headers']);
        self::assertNotSame('', $response['headers']['content-language']);
    }

    public function testBodyIsASingleJsonDocument(): void
    {
        $response = $this->get('/v1/api/users/me', $this->accessToken());

        self::assertStringNotContainsString('}{', $response['raw'], 'Gövdede iki JSON dokümanı var.');
        self::assertNotNull(
            json_decode($response['raw'], true),
            'Gövde geçerli tek bir JSON değil: ' . $response['raw']
        );
    }

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

    public function testUnknownRouteReturns404(): void
    {
        self::assertSame(404, $this->get('/v1/api/olmayan-yol')['status']);
    }

    public function testWrongMethodReturns405(): void
    {
        self::assertSame(405, $this->delete('/v1/api/auth/login')['status']);
    }

    public function testCorsPreflightReturnsEmpty204(): void
    {
        $response = $this->request('OPTIONS', '/v1/api/auth/login');

        self::assertSame(204, $response['status']);
        self::assertSame('', trim($response['raw']), '204 yanıtı gövde taşımamalı.');
    }

    public function testHomeControllerGoesThroughResponseLayer(): void
    {
        $response = $this->get('/v1/api');

        self::assertSame(200, $response['status']);
        $this->assertJsonContentType($response);
        self::assertSame(['status' => 'ok'], $response['body']);
        self::assertSame('nosniff', $response['headers']['x-content-type-options'] ?? null);
        self::assertArrayHasKey('referrer-policy', $response['headers']);
    }

    public function testSecurityHeadersArePresentOnErrorResponses(): void
    {
        $response = $this->get('/v1/api/__no_such_route__');

        self::assertSame(404, $response['status']);

        foreach (['x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy'] as $header) {
            self::assertArrayHasKey($header, $response['headers'], "Hata yanıtında eksik: $header");
        }
    }

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

    public function testDeletingMissingAvatarReturns404(): void
    {
        $response = $this->delete('/v1/api/users/me/avatar', $this->accessToken());

        $this->assertErrorEnvelope($response, 404);
    }
}
