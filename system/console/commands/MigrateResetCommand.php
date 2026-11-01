<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;
use System\Database\Database;
use System\Monitor\NullRecorder;
use System\Database\MigrationRunner;
use System\Database\Schema;

#[AsCommand(
    name: 'migrate:reset',
    description: 'Tüm migration\'ları geri alır',
    usage: 'php frame migrate:reset [--force]'
)]
final class MigrateResetCommand extends Command
{
    public function __construct(
        private readonly AppConfig $app,
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Production ortamında zorla çalıştır');
    }

    protected function handle(): ExitCode
    {
        $this->title('Migration Reset');

        if ($this->app->production && !$this->option('force')) {
            $this->error('Production ortamında reset için --force kullanın.');
            return ExitCode::FAILURE;
        }

        if (!$this->option('force')) {
            $this->caution('Bu işlem TÜM migration\'ları geri alacak!');
            $confirm = $this->confirm('Devam edilsin mi?', false);
            if (!$confirm) {
                $this->warning('İşlem iptal edildi.' . ($this->input->isInteractive() ? '' : ' (etkileşimsiz ortam — onaysız çalıştırmak için --force)'));
                return ExitCode::FAILURE;
            }
        }

        try {
        $runner = $this->runner;

            $rolledBack = $runner->reset();

            if (empty($rolledBack)) {
                $this->info('Geri alınacak migration bulunamadı.');
                return ExitCode::SUCCESS;
            }

            $this->success(count($rolledBack) . ' migration geri alındı:');
            $this->newLine();

            foreach ($rolledBack as $migration) {
                $this->writeln('  ' . $this->output->yellow('↩') . ' ' . $migration);
            }

            $this->newLine();

        } catch (\Throwable $e) {
            $this->error('Reset hatası: ' . $e->getMessage());

            if (!$this->app->production) {
                $this->newLine();
                $this->writeln($this->output->gray($e->getTraceAsString()));
            }

            return ExitCode::FAILURE;
        }

        return ExitCode::SUCCESS;
    }
}
