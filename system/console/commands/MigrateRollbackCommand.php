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
    public function __construct(
        private readonly AppConfig $app,
        private readonly MigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('step', 's', InputOption::VALUE_REQUIRED, 'Geri alınacak migration sayısı (verilmezse son batch\'in tamamı)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Production ortamında zorla çalıştır');
    }

    protected function handle(): ExitCode
    {
        $this->title('Migration Rollback');

        if ($this->app->production && !$this->option('force')) {
            $this->error('Production ortamında rollback için --force kullanın.');
            return ExitCode::FAILURE;
        }

        $rawStep = $this->option('step');
        $steps = null;

        if ($rawStep !== null) {
            if (!ctype_digit((string) $rawStep) || (int) $rawStep < 1) {
                $this->error("--step pozitif bir tam sayı olmalı, verilen: '{$rawStep}'");
                return ExitCode::INVALID;
            }

            $steps = (int) $rawStep;
        }

        if (!$this->option('force')) {
            $what = $steps === null ? 'Son migration batch\'i' : "Son {$steps} migration";
            $confirm = $this->confirm("{$what} geri alınacak. Devam edilsin mi?", false);
            if (!$confirm) {
                $this->warning('İşlem iptal edildi.' . ($this->input->isInteractive() ? '' : ' (etkileşimsiz ortam — onaysız çalıştırmak için --force)'));
                return ExitCode::FAILURE;
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
