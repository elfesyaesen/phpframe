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
    name: 'migrate:rollback',
    description: 'Son migration batch\'ini geri alır',
    usage: 'php frame migrate:rollback [--step=<num>] [--force]'
)]
final class MigrateRollbackCommand extends Command
{
    /**
     * Yapılandırma global sabitlerden değil enjekte edilen typed config'ten okunur (DI-plan §25). Komutlar artık container tarafından çözüldüğü için constructor injection kullanılabiliyor.
     */
    public function __construct(
        private readonly AppConfig $app,
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('step', 's', InputOption::VALUE_REQUIRED, 'Geri alınacak migration sayısı', '1')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Production ortamında zorla çalıştır');
    }

    protected function handle(): ExitCode
    {
        $this->title('Migration Rollback');

        // Production kontrolü
        if ($this->app->production && !$this->option('force')) {
            $this->error('Production ortamında rollback için --force kullanın.');
            return ExitCode::FAILURE;
        }

        $steps = (int) $this->option('step', 1);

        if ($steps < 1) {
            $this->error('Step değeri en az 1 olmalı.');
            return ExitCode::INVALID;
        }

        // Onay al
        if (!$this->option('force')) {
            $confirm = $this->confirm("Son {$steps} migration geri alınacak. Devam edilsin mi?", false);
            if (!$confirm) {
                $this->info('İşlem iptal edildi.');
                return ExitCode::SUCCESS;
            }
        }

        try {
        $runner = $this->runner;

            $rolledBack = $runner->rollback($steps);

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
            $this->error('Rollback hatası: ' . $e->getMessage());

            if (!$this->app->production) {
                $this->newLine();
                $this->writeln($this->output->gray($e->getTraceAsString()));
            }

            return ExitCode::FAILURE;
        }

        return ExitCode::SUCCESS;
    }
}
