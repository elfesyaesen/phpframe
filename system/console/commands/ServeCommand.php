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
        $host = trim((string) $this->option('host', '127.0.0.1'), '[]');
        $rawPort = (string) $this->option('port', '8000');

        if (!ctype_digit($rawPort) || (int) $rawPort < 1 || (int) $rawPort > 65535) {
            $this->error("Geçersiz port: '{$rawPort}' (1-65535 arası olmalı)");
            return ExitCode::INVALID;
        }

        if ($host === '' || preg_match('/[\s\/]/', $host)) {
            $this->error("Geçersiz host: '{$host}'");
            return ExitCode::INVALID;
        }

        $port = (int) $rawPort;

        $address = str_contains($host, ':') ? "[{$host}]" : $host;

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

        $this->writeln('  ' . $this->output->cyan('Sunucu:') . " http://{$address}:{$port}");
        $this->writeln('  ' . $this->output->cyan('Root:') . '   ' . $docroot);
        $this->newLine();
        $this->writeln($this->output->yellow('Durdurmak için Ctrl+C'));
        $this->newLine();

        $command = sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($address . ':' . $port),
            escapeshellarg($docroot),
            escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'serve-router.php')
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function isPortInUse(string $host, int $port): bool
    {
        $probe = match ($host) {
            '0.0.0.0' => '127.0.0.1',
            '::' => '[::1]',
            default => str_contains($host, ':') ? "[{$host}]" : $host,
        };

        $connection = @fsockopen($probe, $port, $errno, $errstr, 1);

        if ($connection !== false) {
            fclose($connection);
            return true;
        }

        return false;
    }
}
