<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\ManagesCaches;
use System\Console\ExitCode;

#[AsCommand(
    name: 'optimize:clear',
    description: 'Route, Twig ve eski container cache\'lerinin hepsini siler',
    usage: 'php frame optimize:clear'
)]
final class OptimizeClearCommand extends Command
{
    use ManagesCaches;

    public function __construct(
        protected readonly AppConfig $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function handle(): ExitCode
    {
        $this->title('Cache Temizleme');

        $exit = $this->runCacheSteps(
            [$this->clearRouteCache(...), $this->clearTwigCache(...), $this->clearLegacyContainerCache(...)],
            'Tüm cache\'ler temizlendi.'
        );

        if ($exit === ExitCode::SUCCESS && $this->app->production) {
            $this->note('FPM OPcache için: sudo systemctl reload php8.5-fpm');
        }

        return $exit;
    }
}
