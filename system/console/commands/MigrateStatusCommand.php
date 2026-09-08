<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Database\Database;
use System\Monitor\NullRecorder;
use System\Database\MigrationRunner;
use System\Database\Schema;

#[AsCommand(
    name: 'migrate:status',
    description: 'Migration durumunu gösterir',
    usage: 'php frame migrate:status'
)]
final class MigrateStatusCommand extends Command
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
        // Bu komut için ek argüman/option gerekmiyor
    }

    protected function handle(): ExitCode
    {
        $this->title('Migration Durumu');

        try {
        $runner = $this->runner;

            $status = $runner->status();

            if (empty($status)) {
                $this->info('Hiç migration dosyası bulunamadı.');
                $this->newLine();
                $this->writeln('Yeni migration oluşturmak için:');
                $this->writeln('  php frame make:migration create_example_table');
                return ExitCode::SUCCESS;
            }

            $rows = [];
            foreach ($status as $item) {
                $statusText = $item['status'] === 'Ran'
                    ? $this->output->green('Ran')
                    : $this->output->yellow('Pending');

                $rows[] = [
                    $item['migration'],
                    $item['batch'] ?? '-',
                    $statusText,
                ];
            }

            $this->table(['Migration', 'Batch', 'Durum'], $rows);

            // Özet
            $ranCount = count(array_filter($status, fn($s) => $s['status'] === 'Ran'));
            $pendingCount = count($status) - $ranCount;

            $this->newLine();
            $this->writeln(sprintf(
                '  Toplam: %d | Çalıştırılmış: %s | Bekleyen: %s',
                count($status),
                $this->output->green((string) $ranCount),
                $pendingCount > 0 ? $this->output->yellow((string) $pendingCount) : '0'
            ));
            $this->newLine();

        } catch (\Throwable $e) {
            $this->error('Hata: ' . $e->getMessage());

            if (!$this->app->production) {
                $this->newLine();
                $this->writeln($this->output->gray($e->getTraceAsString()));
            }

            return ExitCode::FAILURE;
        }

        return ExitCode::SUCCESS;
    }
}
