<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Config\MonitorConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\ManagesCaches;
use System\Console\ExitCode;
use System\Routing\RouterFactory;

#[AsCommand(
    name: 'route:cache',
    description: 'Route cache dosyasını üretir',
    usage: 'php frame route:cache [--force]'
)]
final class RouteCacheCommand extends Command
{
    use ManagesCaches;

    public function __construct(
        protected readonly AppConfig $app,
        private readonly RouterFactory $routerFactory,
        private readonly MonitorConfig $monitorConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', description: 'APP_PRODUCTION=false olsa da üretir (CI artifact ön-üretimi)');
    }

    protected function handle(): ExitCode
    {
        $this->title('Route Cache');

        if (!$this->allowCacheBuild()) {
            return ExitCode::FAILURE;
        }

        return $this->runCacheSteps(
            [fn(): bool => $this->buildRouteCache($this->routerFactory, $this->monitorConfig)],
            'Route cache hazır.'
        );
    }
}
