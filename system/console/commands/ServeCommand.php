<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputOption;

#[AsCommand(
    name: 'serve',
    description: 'PHP built-in development sunucusunu başlatır',
    usage: 'php frame serve [--host=127.0.0.1] [--port=8000]'
)]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', 'H', InputOption::VALUE_REQUIRED, 'Sunucu adresi', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port numarası', '8000');
    }

    protected function handle(): ExitCode
    {
        $host = $this->option('host', '127.0.0.1');
        $port = (int) $this->option('port', 8000);

        // Port kullanımda mı kontrol et
        if ($this->isPortInUse($host, $port)) {
            $this->error("Port {$port} zaten kullanımda!");
            $this->writeln("Farklı bir port deneyin: php frame serve --port=8080");
            return ExitCode::FAILURE;
        }

        $this->newLine();
        $this->writeln($this->output->green('Frame Development Server'));
        $this->writeln(str_repeat('─', 40));
        $this->newLine();
        $docroot = APP_ROOT . DIRECTORY_SEPARATOR . 'public';

        $this->writeln('  ' . $this->output->cyan('Sunucu:') . " http://{$host}:{$port}");
        $this->writeln('  ' . $this->output->cyan('Root:') . '   ' . $docroot);
        $this->newLine();
        $this->writeln($this->output->yellow('Durdurmak için Ctrl+C'));
        $this->newLine();

        // PHP built-in server başlat. PHP_BINARY: PATH'teki rastgele 'php' yerine bu
        // CLI'yi çalıştıran yorumlayıcı (Ubuntu'da php8.5 vs php ayrımı). host:port tek
        // token olarak escapeshellarg ile sarılır (komut enjeksiyonu önlenir).
        //
        // ── DÜZELTME ─────────────────────────────────────────────────────
        // Eskiden docroot APP_ROOT, router ise `APP_ROOT/index.php` idi.
        // O DOSYA YOK — front controller `public/index.php`. Sonuç: `frame
        // serve` ile açılan her istek boş gövdeli 500 dönüyordu, yani komut
        // hiç çalışmıyordu.
        //
        // Docroot artık `public/`: Apache/nginx yapılandırmasıyla (kök
        // .htaccess'in "Bu klasor DocumentRoot OLMAMALIDIR" uyarısı) aynı
        // hizaya gelir, böylece dev sunucusu production'la aynı dosya
        // görünürlüğüne sahip olur — `.env`, `logs/`, `config/` erişilemez.
        //
        // Router script olarak yine `public/index.php` verilir: built-in
        // sunucu var olan statik dosyaları kendisi servis eder, kalan her
        // yolu front controller'a yönlendirir (.htaccess rewrite karşılığı).
        $command = sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host . ':' . $port),
            escapeshellarg($docroot),
            escapeshellarg($docroot . DIRECTORY_SEPARATOR . 'index.php')
        );

        // Windows için passthru kullan
        passthru($command, $exitCode);

        return $exitCode === 0 ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function isPortInUse(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection !== false) {
            fclose($connection);
            return true;
        }

        return false;
    }
}
