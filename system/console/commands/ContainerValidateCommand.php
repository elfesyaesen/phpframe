<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\BuildsContainer;
use System\Console\ExitCode;
use Throwable;

#[AsCommand(
    name: 'container:validate',
    description: 'Container tanımlarını doğrular (döngü, scope, factory, eksik binding)',
    usage: 'php frame container:validate [--summary]',
    aliases: ['c:v']
)]
final class ContainerValidateCommand extends Command
{
    use BuildsContainer;

    protected function configure(): void
    {
        $this->addOption(
            'summary',
            's',
            description: 'Bulguları tek satır özet olarak basar'
        );

    }

    protected function handle(): ExitCode
    {
        $this->title('Container Doğrulama');

        try {
            $compiler = $this->compiler();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return ExitCode::FAILURE;
        }

        $result = $compiler->compile(generate: false);
        $builder = $this->builder();

        $this->table(
            ['ölçüm', 'değer'],
            [
                ['kök servis', (string) count($result->roots)],
                ['servis', (string) $result->serviceCount()],
                ['bağımlılık kenarı', (string) ($result->stats['edges'] ?? 0)],
                ['atlanan', (string) count($result->skipped)],
                ['süre', number_format((float) ($result->stats['ms_total'] ?? 0), 1) . ' ms'],
            ]
        );

        $overridden = $builder->overriddenDefinitions();

        if ($overridden !== []) {
            $this->newLine();
            $this->section('Üzerine yazılmış tanımlar (' . count($overridden) . ')');
            $this->writeln($this->output->gray(
                '  Aynı id iki kez tanımlanmış; kazanan liste sırasına bağlı.'
            ));
            $this->newLine();

            foreach ($overridden as $entry) {
                $this->writeln('  ' . $entry['id']);
                $this->writeln('    ' . $this->output->gray('ezilen : ' . ($entry['previous'] ?? '?')));
                $this->writeln('    ' . $this->output->gray('kazanan: ' . ($entry['current'] ?? '?')));
            }
        }

        $this->renderDiagnostics($result->diagnostics, full: !(bool) $this->option('summary'));

        if ($result->skipped !== [] && $this->isVerbose()) {
            $this->newLine();
            $this->section('Atlanan servisler (' . count($result->skipped) . ')');
            foreach ($result->skipped as $id => $reason) {
                $this->writeln('  ' . $id);
                $this->writeln('    ' . $this->output->gray($reason));
            }
        } elseif ($result->skipped !== []) {
            $this->newLine();
            $this->writeln($this->output->gray(
                '  ' . count($result->skipped) . ' servis atlandı — ayrıntı için -v'
            ));
        }

        $exit = ExitCode::SUCCESS;

        if (!$result->isSuccessful()) {
            $this->newLine();
            $this->error(count($result->errors()) . ' hata — bu servisler çalışma anında kurulamaz.');
            $exit = ExitCode::FAILURE;
        }

        if ($exit === ExitCode::SUCCESS) {
            $this->newLine();
            $this->success('Doğrulama temiz — ' . $result->serviceCount() . ' servis.');
        }

        return $exit;
    }
}
