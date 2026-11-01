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
    name: 'optimize',
    description: 'Route ve Twig cache\'lerini üretir (production deploy build adımı)',
    usage: 'php frame optimize [--force]',
    aliases: ['opt']
)]
final class OptimizeCommand extends Command
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
        $this->title('Production Optimizasyonu');

        if (!$this->allowCacheBuild()) {
            return ExitCode::FAILURE;
        }

        $exit = $this->runCacheSteps(
            [
                fn(): bool => $this->buildRouteCache($this->routerFactory, $this->monitorConfig),
                $this->clearTwigCache(...),
                $this->buildTwigCache(...),
            ],
            'Optimizasyon tamamlandı.'
        );

        if ($exit === ExitCode::SUCCESS) {
            $this->note('Değişikliklerin canlıya yansıması için: sudo systemctl reload php8.5-fpm');
        }

        return $exit;
    }
}
