<?php

declare(strict_types=1);

namespace System\Runtime;

use System\Config\AppConfig;
use System\Config\MonitorConfig;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Contract\ScopeInterface;
use System\Container\Contract\ServiceProviderInterface;
use System\Container\Core\ContainerBuilder;
use System\Monitor\Contracts\RecorderInterface;
use Throwable;

final class Kernel
{
    private ?ScopeInterface $requestScope = null;

    private function __construct(
        private readonly ContainerInterface $container,
        private readonly ShutdownRegistry $shutdown,
        private readonly AppConfig $app,
        private readonly Sapi $sapi,
    ) {}

    public static function create(Sapi $sapi, string $appRoot): self
    {
        $shutdown = new ShutdownRegistry();

        try {
            $instances = RuntimeInstances::build($appRoot, $shutdown, validate: true);
        } catch (Throwable $e) {
            self::fail($e->getMessage());
        }

        /** @var AppConfig $app */
        $app = $instances[AppConfig::class];

        $providers = self::providers($appRoot, null);

        $container = self::built($instances, $providers);

        $kernel = new self($container, $shutdown, $app, $sapi);

        foreach ($providers as $provider) {
            if ($provider instanceof BootableProviderInterface && $provider->supports($sapi)) {
                $provider->boot($container);
            }
        }

        return $kernel;
    }

    private static function built(
        array $instances,
        array $providers,
    ): ContainerInterface {
        $builder = new ContainerBuilder();

        foreach ($instances as $id => $value) {
            $builder->instance($id, $value);
        }

        foreach ($providers as $provider) {
            $provider->register($builder);
        }

        return $builder->lock()->build();
    }

    /**
     * @return list<ServiceProviderInterface>
     */
    private static function providers(string $appRoot, ?Sapi $sapi): array
    {
        try {
            return ContainerBuilder::instantiateProviders($appRoot, $sapi);
        } catch (Throwable $e) {
            self::fail($e->getMessage());
        }
    }

    public function beginRequestScope(): ScopeInterface
    {
        if ($this->requestScope !== null) {
            return $this->requestScope;
        }

        $scope = $this->container->createScope($this->sapi->value);
        $this->requestScope = $scope;

        $scope->set(RequestSnapshot::class, $this->captureSnapshot());

        $this->sendRequestIdHeader($scope);

        $this->shutdown->on(
            ShutdownPriority::SCOPE_DISPOSE,
            static function () use ($scope): void {
                $scope->dispose();
            },
        );

        return $scope;
    }

    public function endRequestScope(): void
    {
        $scope = $this->requestScope;

        if ($scope === null || $scope->isDisposed()) {
            return;
        }

        if (!$this->sapi->isCli() && $this->container->get(MonitorConfig::class)->enabled) {
            try {
                $scope->get(RecorderInterface::class)->flush();
            } catch (Throwable) {
            }
        }

        $scope->dispose();
    }

    private function sendRequestIdHeader(ScopeInterface $scope): void
    {
        if ($this->sapi->isCli() || headers_sent()) {
            return;
        }

        header('X-Request-ID: ' . $scope->get(RequestId::class)->value);
    }

    private function captureSnapshot(): RequestSnapshot
    {
        if ($this->sapi->isCli()) {
            return RequestSnapshot::forCli();
        }

        $monitor = $this->container->get(MonitorConfig::class);

        return RequestSnapshot::capture(
            server: $_SERVER,
            bufferingResponse: $monitor->enabled && $monitor->captureResponseBody,
        );
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function shutdown(): ShutdownRegistry
    {
        return $this->shutdown;
    }

    public function app(): AppConfig
    {
        return $this->app;
    }

    public function sapi(): Sapi
    {
        return $this->sapi;
    }

    /**
     * @return never
     */
    private static function fail(string $message): never
    {
        if (PHP_SAPI === 'cli') {
            exit($message . PHP_EOL);
        }

        error_log('PHPFrame boot hatası: ' . $message);

        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 60');

        exit('{"error":{"code":503,"message":"Service unavailable"}}');
    }
}
