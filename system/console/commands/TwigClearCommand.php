<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\ManagesCaches;
use System\Console\ExitCode;

#[AsCommand(
    name: 'twig:clear',
    description: 'Twig cache\'ini siler',
    usage: 'php frame twig:clear'
)]
final class TwigClearCommand extends Command
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
        $this->title('Twig Cache Temizleme');

        return $this->runCacheSteps([$this->clearTwigCache(...)], 'Twig cache temizlendi.');
    }
}
