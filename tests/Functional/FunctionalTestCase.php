<?php

declare(strict_types=1);

namespace Tests\Functional;

use PHPUnit\Framework\TestCase;
use Redis;
use System\Config\RedisConfig;
use Throwable;

abstract class FunctionalTestCase extends TestCase
{
    protected static string $baseUrl = '';

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

    protected function clearRateLimits(): void
    {
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
        }

        foreach (glob(APP_ROOT . '/storage/cache/rate/*.rate') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @param array<string, mixed>|null $body
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
            CURLOPT_FOLLOWLOCATION => false,
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

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>}
     */
    protected function get(string $path, ?string $token = null): array
    {
        return $this->request('GET', $path, null, $token);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>}
     */
    protected function post(string $path, ?array $body = null, ?string $token = null): array
    {
        return $this->request('POST', $path, $body, $token);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>}
     */
    protected function put(string $path, ?array $body = null, ?string $token = null): array
    {
        return $this->request('PUT', $path, $body, $token);
    }

    /** @return array{status: int, body: array<string, mixed>|null, raw: string, headers: array<string, string>} */
    protected function delete(string $path, ?string $token = null): array
    {
        return $this->request('DELETE', $path, null, $token);
    }

    /**
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

    /**
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
