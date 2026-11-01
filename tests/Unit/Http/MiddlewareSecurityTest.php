<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use System\Config\AppConfig;
use System\Config\HttpConfig;
use System\Config\RateLimitConfig;
use System\Exceptions\ForbiddenException;
use System\Exceptions\HttpException;
use System\Exceptions\InternalServerException;
use System\Exceptions\TooManyRequestsException;
use System\Http\Request;
use System\Http\Response;
use System\Logging\Contracts\LoggerInterface;
use System\Middleware\RateLimitMiddleware;
use System\Routing\Router;
use System\Security\RateLimiter;

#[CoversClass(Router::class)]
#[CoversClass(RateLimitMiddleware::class)]
#[CoversClass(HttpException::class)]
final class MiddlewareSecurityTest extends TestCase
{
    private string $rateDir;

    protected function setUp(): void
    {
        $this->rateDir = sys_get_temp_dir() . '/phpframe-rate-' . bin2hex(random_bytes(4));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->rateDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->rateDir)) {
            rmdir($this->rateDir);
        }
    }

    public function testNestedGroupInheritsParentPrefixAndMiddleware(): void
    {
        $router = $this->router();

        $router->group(['prefix' => '/v1/api/admin', 'middleware' => ['api_auth', 'api_superadmin']], function (Router $r): void {
            $r->group(['prefix' => '/reports/', 'middleware' => ['rate_limit:10,60']], function (Router $r): void {
                $r->get('/sales', ['C', 'm'])->middleware('rate_limit:5,60');
            });
            $r->get('/roles', ['C', 'm']);
        });
        $router->get('/outside', ['C', 'm']);

        [$nested, $sibling, $outside] = $router->getRoutes();

        self::assertSame('/v1/api/admin/reports/sales', $nested['uri']);
        self::assertSame(['api_auth', 'api_superadmin', 'rate_limit:10,60', 'rate_limit:5,60'], $nested['middleware']);

        self::assertSame('/v1/api/admin/roles', $sibling['uri']);
        self::assertSame(['api_auth', 'api_superadmin'], $sibling['middleware']);

        self::assertSame('/outside', $outside['uri']);
        self::assertSame([], $outside['middleware']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRateLimitProvider(): array
    {
        return [
            'noktalı virgül' => ['5;60'],
            'sıfır'          => ['0'],
            'sıfır pencere'  => ['5,0'],
            'negatif'        => ['-1'],
            'metin'          => ['abc'],
            'üç parça'       => ['5,60,1'],
            'boşluklu'       => ['5, 60'],
        ];
    }

    #[DataProvider('invalidRateLimitProvider')]
    public function testInvalidRateLimitParameterFailsInsteadOfFallingBackToDefaults(string $parameter): void
    {
        $called = false;

        try {
            $this->rateLimit()->handle($this->request(), $this->createStub(Response::class), function () use (&$called): void {
                $called = true;
            }, $parameter);
            self::fail("'{$parameter}' hata vermeliydi.");
        } catch (InternalServerException) {
            self::assertFalse($called, 'Bozuk limitle istek controller\'a ulaşmamalı.');
        }
    }

    public function testStackedRateLimitsKeepSeparateCounters(): void
    {
        $limiter = $this->rateLimit();
        $request = $this->request();

        $send = function () use ($limiter, $request): void {
            $limiter->handle($request, $this->createStub(Response::class), function (Request $r) use ($limiter): void {
                $limiter->handle($r, $this->createStub(Response::class), static function (): void {}, '2,60');
            });
        };

        $send();
        $send();

        $this->expectException(TooManyRequestsException::class);
        $send();
    }

    public function testForbiddenResponseHidesContextOutsideDebug(): void
    {
        $e = new ForbiddenException('Bu işlem için yetkiniz yok', context: [
            'required_roles' => ['administrator'],
            'user_role'      => 'user',
        ]);

        $public = $e->toArray(debug: false);
        self::assertArrayNotHasKey('details', $public['error']);
        self::assertSame('Bu işlem için yetkiniz yok', $public['error']['message']);
        self::assertStringNotContainsString('administrator', (string) json_encode($public));

        self::assertSame('user', $e->toArray(debug: true)['error']['details']['user_role']);
    }

    private function router(): Router
    {
        return new Router(
            $this->createStub(ContainerInterface::class),
            $this->createStub(LoggerInterface::class),
            new AppConfig(root: '/tmp', url: 'http://localhost', production: true, timezone: 'UTC'),
            new HttpConfig(trustedProxies: [], corsOrigins: []),
        );
    }

    private function rateLimit(): RateLimitMiddleware
    {
        return new RateLimitMiddleware(new RateLimiter($this->rateDir), new RateLimitConfig(60, 60));
    }

    private function request(): Request
    {
        $request = new Request();
        $request->setMatchedRoute('POST', '/auth/login', 'auth.login');

        return $request;
    }
}
