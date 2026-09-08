<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\BuildsContainer;
use System\Console\ExitCode;
use Throwable;

/**
 * Derlemeden doğrular (DI-plan §36).
 *
 * Projede test framework'ü olmadığı için BİRİNCİL DOĞRULAMA ARACI budur:
 * döngü, scope ihlali, eksik binding, derlenemez factory ve üzerine yazılmış
 * tanımlar burada görülür.
 *
 * `--check` deploy/CI kapısıdır: canlı derlemenin hash'ini mevcut tanımlarla
 * karşılaştırır ve farklıysa non-zero döner. "Recompile etmeyi unuttum" bir
 * production olayı olmak yerine kırmızı bir build olur (DI-plan §32).
 */
#[AsCommand(
    name: 'container:validate',
    description: 'Container tanımlarını doğrular (döngü, scope, factory, eksik binding)',
    usage: 'php frame container:validate [--check] [--summary] [--definitions-only]',
    aliases: ['c:v']
)]
final class ContainerValidateCommand extends Command
{
    use BuildsContainer;

    protected function configure(): void
    {
        $this->addOption(
            'check',
            null,
            description: 'Canlı derlemenin güncel olduğunu da doğrular (CI/deploy kapısı)'
        );

        $this->addOption(
            'summary',
            's',
            description: 'Bulguları tek satır özet olarak basar'
        );

        $this->addOption(
            'ci',
            null,
            description: 'Kaynak parmak izinde içerik hash\'i kullanır (--check ile CI\'da zorunlu)'
        );
    }

    protected function handle(): ExitCode
    {
        $this->title('Container Doğrulama');

        $ci = (bool) $this->option('ci');

        try {
            $compiler = $this->compiler(useContentHash: $ci);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return ExitCode::FAILURE;
        }

        $result = $compiler->compile(generate: false);
        $builder = $this->builder();

        // ── Genel tablo ────────────────────────────────────────────
        $this->table(
            ['ölçüm', 'değer'],
            [
                ['kök servis', (string) count($result->roots)],
                ['derlenen servis', (string) $result->serviceCount()],
                ['bağımlılık kenarı', (string) ($result->stats['edges'] ?? 0)],
                ['atlanan', (string) count($result->skipped)],
                ['hash', $result->hash],
                ['süre', number_format((float) ($result->stats['ms_total'] ?? 0), 1) . ' ms'],
            ]
        );

        // ── Üzerine yazılmış tanımlar ──────────────────────────────
        // Eski imperative bootstrap'ta aynı id'yi iki kez kaydetmek sessizce
        // üzerine yazıyordu ve hangisinin kazandığı satır sırasına bağlıydı.
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

        // ── Bulgular ───────────────────────────────────────────────
        $this->renderDiagnostics($result->diagnostics, full: !(bool) $this->option('summary'));

        // ── Atlananlar ─────────────────────────────────────────────
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
            $this->error(count($result->errors()) . ' hata — container derlenemez.');
            $exit = ExitCode::FAILURE;
        }

        // ── Bayatlık kapısı ────────────────────────────────────────
        if ((bool) $this->option('check')) {
            $this->newLine();
            $this->section('Bayatlık kontrolü (--check)');

            $loader = $this->loader();
            $liveHash = $loader->liveHash();

            if ($liveHash === null) {
                $this->error(
                    'Derlenmiş container yok.' . "\n"
                    . '  Deploy build adımına ekle: php frame container:compile'
                );

                return ExitCode::FAILURE;
            }

            if ($liveHash !== $result->hash) {
                $this->error(
                    'Derlenmiş container BAYAT.' . "\n"
                    . '  derlenmiş: ' . $liveHash . "\n"
                    . '  güncel   : ' . $result->hash . "\n"
                    . '  Çözüm: php frame container:compile'
                );

                return ExitCode::FAILURE;
            }

            $this->writeln('  ' . $this->output->green('✓') . ' canlı derleme güncel');

            if (!$ci) {
                $this->newLine();
                $this->note(
                    'CI\'da --ci ile çalıştır: kaynak parmak izi varsayılan olarak mtime'
                    . ' kullanır,' . "\n" . 'mtime ise taze bir checkout\'ta tekrarlanabilir'
                    . ' değildir (her koşuda farklı hash).'
                );
            }
        }

        if ($exit === ExitCode::SUCCESS) {
            $this->newLine();
            $this->success('Doğrulama temiz — ' . $result->serviceCount() . ' servis.');
        }

        return $exit;
    }
}
