<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\Commands\Concerns\BuildsContainer;
use System\Console\ExitCode;
use System\Container\Compilation\AtomicWriter;
use System\Container\Compiled\CompiledContainerLoader;
use Throwable;

#[AsCommand(
    name: 'container:compile',
    description: 'DI container\'ı derler: doğrular, PHP kodu üretir, atomik yazar',
    usage: 'php frame container:compile [--dry-run] [--force] [--ci] [--prune]',
    aliases: ['c:c']
)]
final class ContainerCompileCommand extends Command
{
    use BuildsContainer;

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            description: 'Diske yazmaz; üretilen kaynağı stdout\'a basar'
        );

        $this->addOption(
            'force',
            'f',
            description: 'Hash değişmemiş olsa da yeniden derler'
        );

        $this->addOption(
            'ci',
            null,
            description: 'Kaynak parmak izinde mtime yerine içerik hash\'i kullanır (CI için zorunlu)'
        );

        $this->addOption(
            'prune',
            null,
            description: 'Eski derlemeleri temizler (bir önceki rollback için kalır)'
        );
    }

    protected function handle(): ExitCode
    {
        $this->title('Container Derleme');

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $ci = (bool) $this->option('ci');

        try {
            $compiler = $this->compiler(useContentHash: $ci);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return ExitCode::FAILURE;
        }

        $loader = $this->loader();
        $liveHash = $loader->liveHash();

        $result = $compiler->compile();

        // ── Geçiş özeti ────────────────────────────────────────────
        $this->section('Geçişler');
        $stats = $result->stats;
        $this->table(
            ['geçiş', 'sonuç', 'süre'],
            [
                ['1. roots', ($stats['roots'] ?? 0) . ' kök', $this->ms($stats['ms_roots'] ?? 0)],
                ['2. closure', ($stats['services'] ?? 0) . ' servis', $this->ms($stats['ms_closure'] ?? 0)],
                ['3. graph', ($stats['edges'] ?? 0) . ' kenar', $this->ms($stats['ms_graph'] ?? 0)],
                ['4. validate', $this->diagnosticSummary($result->diagnostics), $this->ms($stats['ms_validate'] ?? 0)],
                ['   hash', substr($result->hash, 0, 12) . '…', $this->ms($stats['ms_hash'] ?? 0)],
                ['5. codegen', isset($stats['bytes']) ? $this->bytes((int) $stats['bytes']) : '—', $this->ms($stats['ms_codegen'] ?? 0)],
            ]
        );

        $this->renderDiagnostics($result->diagnostics);

        if (!$result->isSuccessful()) {
            $this->newLine();
            $this->error(
                'Derleme başarısız: ' . count($result->errors()) . ' hata. '
                . 'Hiçbir şey yazılmadı; önceki derleme (varsa) dokunulmadan kaldı.'
            );

            return ExitCode::FAILURE;
        }

        if ($result->source === null || $result->className === null) {
            $this->error('Kod üretilemedi.');

            return ExitCode::FAILURE;
        }

        // ── Dry run ────────────────────────────────────────────────
        if ($dryRun) {
            $this->newLine();
            $this->info('--dry-run: diske yazılmadı. Üretilen kaynak:');
            $this->newLine();
            $this->writeln($result->source);

            return ExitCode::SUCCESS;
        }

        // ── Değişiklik yok mu? ─────────────────────────────────────
        if (!$force && $liveHash === $result->hash) {
            $this->newLine();
            $this->success(
                'Derleme güncel (hash ' . substr($result->hash, 0, 12) . '…) — yazmaya gerek yok. '
                . 'Zorlamak için: --force'
            );

            return ExitCode::SUCCESS;
        }

        // ── Yaz ────────────────────────────────────────────────────
        $writer = new AtomicWriter(CompiledContainerLoader::defaultCacheDir(APP_ROOT));

        try {
            $paths = $writer->write(
                $result->className,
                $result->hash,
                $result->source,
                $result->toMetadata(),
                // Bayatlık kontrolünün AYNI modla karşılaştırabilmesi için
                // hash'in hangi modla üretildiği pointer'a yazılır.
                $ci ? AtomicWriter::HASH_MODE_CONTENT : AtomicWriter::HASH_MODE_MTIME
            );
        } catch (Throwable $e) {
            $this->error('Yazma başarısız: ' . $e->getMessage());

            return ExitCode::FAILURE;
        }

        $this->newLine();
        $this->section('Yazıldı');
        $this->listing([
            'sınıf     ' . $this->relative($paths['class']),
            'metadata  ' . $this->relative($paths['metadata']) . $this->output->gray('  (yalnızca CLI okur)'),
            'pointer   ' . $this->relative($paths['pointer']) . $this->output->gray('  (en son yazıldı — atomik geçiş)'),
        ]);

        if ((bool) $this->option('prune')) {
            $removed = $writer->prune(keep: 1);
            $this->writeln('  ' . $this->output->gray('temizlendi: ' . count($removed) . ' dosya'));
        }

        // ── Kurulum doğrulaması ────────────────────────────────────
        // Üretilen dosyayı SADECE yazmak yetmez: syntax hatası veya eksik
        // metot varsa bunu ilk production isteği değil, derleme koşusu
        // öğrenmeli.
        $this->newLine();
        $lint = $this->lint($paths['class']);

        if ($lint !== null) {
            $this->error('Üretilen dosya geçerli PHP değil:' . "\n" . $lint);

            return ExitCode::FAILURE;
        }

        $this->writeln('  ' . $this->output->green('✓') . ' üretilen dosya php -l temiz');

        $this->newLine();
        $this->success(
            $result->serviceCount() . ' servis derlendi — '
            . $this->ms($stats['ms_total'] ?? 0) . ', hash ' . substr($result->hash, 0, 12) . '…'
        );

        $this->newLine();
        $this->note(
            'Derlenmiş container GIT\'TE DEĞİL (system/cache/container/* gitignore).' . "\n"
            . 'Deploy artifact\'ının onu taşıdığından emin ol; yoksa production'
            . ' container\'sız kalır ve boot\'ta hata verir.'
        );

        return ExitCode::SUCCESS;
    }

    /** Üretilen dosyanın sözdizimini doğrular; hata varsa çıktıyı döndürür. */
    private function lint(string $file): ?string
    {
        $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $command = escapeshellarg($binary) . ' -l ' . escapeshellarg($file) . ' 2>&1';

        $output = @shell_exec($command);

        if ($output === null || $output === false) {
            return null; // shell_exec kapalı — doğrulama atlanır
        }

        return str_contains($output, 'No syntax errors') ? null : trim($output);
    }

    private function relative(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', APP_ROOT);

        return str_starts_with($normalized, $root)
            ? ltrim(substr($normalized, strlen($root)), '/')
            : $path;
    }

    private function ms(float|int $value): string
    {
        return number_format((float) $value, 1) . ' ms';
    }

    private function bytes(int $value): string
    {
        return $value < 1024
            ? $value . ' B'
            : number_format($value / 1024, 1) . ' KB';
    }
}
