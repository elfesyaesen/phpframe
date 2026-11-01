<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\ManagesCaches;
use System\Console\ExitCode;

#[AsCommand(
    name: 'route:clear',
    description: 'Route cache\'ini siler',
    usage: 'php frame route:clear'
)]
final class RouteClearCommand extends Command
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
        $this->title('Route Cache Temizleme');

        return $this->runCacheSteps([$this->clearRouteCache(...)], 'Route cache temizlendi.');
    }
}
