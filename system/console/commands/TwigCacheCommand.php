<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\ManagesCaches;
use System\Console\ExitCode;

#[AsCommand(
    name: 'twig:cache',
    description: 'Modüllerin Twig şablonlarını derleyip cache\'e yazar',
    usage: 'php frame twig:cache [--force]'
)]
final class TwigCacheCommand extends Command
{
    use ManagesCaches;

    public function __construct(
        protected readonly AppConfig $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', description: 'APP_PRODUCTION=false olsa da üretir (CI artifact ön-üretimi)');
    }

    protected function handle(): ExitCode
    {
        $this->title('Twig Cache');

        if (!$this->allowCacheBuild()) {
            return ExitCode::FAILURE;
        }

        return $this->runCacheSteps(
            [$this->clearTwigCache(...), $this->buildTwigCache(...)],
            'Twig cache hazır.'
        );
    }
}
