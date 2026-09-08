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
    name: 'migrate',
    description: 'Veritabanı migration\'larını çalıştırır',
    usage: 'php frame migrate [--force] [--step]'
)]
final class MigrateCommand extends Command
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
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Production ortamında zorla çalıştır')
            ->addOption('step', 's', InputOption::VALUE_NONE, 'Migration\'ları tek tek çalıştır');
    }

    protected function handle(): ExitCode
    {
        $this->title('Migration');

        // Production kontrolü
        if ($this->app->production && !$this->option('force')) {
            $this->error('Production ortamında migration çalıştırmak için --force kullanın.');
            return ExitCode::FAILURE;
        }

        try {
        $runner = $this->runner;

            $step = (bool) $this->option('step');
            $ran = $runner->migrate($step);

            if (empty($ran)) {
                $this->info('Çalıştırılacak migration bulunamadı.');
                return ExitCode::SUCCESS;
            }

            $this->success(count($ran) . ' migration çalıştırıldı:');
            $this->newLine();

            foreach ($ran as $migration) {
                $this->writeln('  ' . $this->output->green('✓') . ' ' . $migration);
            }

            $this->newLine();

        } catch (\Throwable $e) {
            $this->error('Migration hatası: ' . $e->getMessage());

            if (!$this->app->production) {
                $this->newLine();
                $this->writeln($this->output->gray($e->getTraceAsString()));
            }

            return ExitCode::FAILURE;
        }

        return ExitCode::SUCCESS;
    }
}
