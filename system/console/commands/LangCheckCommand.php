<?php

declare(strict_types=1);

namespace System\Console\Commands;

use MessageFormatter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use System\Config\AppConfig;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use Throwable;

#[AsCommand(
    name: 'lang:check',
    description: 'Çeviri dosyalarında eksik anahtar, yer tutucu farkı ve sözdizimi hatası arar',
    usage: 'php frame lang:check [--unused]'
)]
final class LangCheckCommand extends Command
{
    private const TRANS_CALL = '/\btrans\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/';

    public function __construct(
        private readonly AppConfig $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'unused',
            'u',
            description: 'Kodda sabit olarak geçmeyen (olası kullanılmayan) anahtarları da listeler'
        );
    }

    protected function handle(): ExitCode
    {
        $this->title('Çeviri Denetimi');

        $catalogs = $this->loadCatalogs();

        if ($catalogs === null) {
            return ExitCode::FAILURE;
        }

        $locales = array_keys($catalogs);
        $allKeys = array_keys(array_merge(...array_values($catalogs)));
        sort($allKeys);

        $this->writeln(sprintf(
            '  %d dil (%s), %d anahtar',
            count($locales),
            implode(', ', $locales),
            count($allKeys),
        ));

        $errors = 0;
        $errors += $this->reportMissing($catalogs, $allKeys);
        $errors += $this->reportPlaceholders($catalogs, $allKeys);
        $errors += $this->reportSyntax($catalogs);

        $used = $this->keysUsedInCode();
        $errors += $this->reportUndefined($used, $allKeys);

        if ((bool) $this->option('unused')) {
            $this->reportUnused($used, $allKeys);
        }

        $this->newLine();

        if ($errors > 0) {
            $this->error("{$errors} sorun bulundu.");

            return ExitCode::FAILURE;
        }

        $this->success('Çeviriler tutarlı.');

        return ExitCode::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function loadCatalogs(): ?array
    {
        $dir = $this->app->langPath();
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        if ($files === []) {
            $this->error('Çeviri dosyası bulunamadı: ' . $dir);

            return null;
        }

        $catalogs = [];

        foreach ($files as $file) {
            $locale = basename($file, '.php');

            try {
                $catalog = require $file;
            } catch (Throwable $e) {
                $this->error("{$locale}.php yüklenemedi: " . $e->getMessage());

                return null;
            }

            if (!is_array($catalog)) {
                $this->error("{$locale}.php bir dizi döndürmüyor.");

                return null;
            }

            $catalogs[$locale] = $catalog;
        }

        return $catalogs;
    }

    /**
     * @param array<string, array<string, mixed>> $catalogs
     * @param list<string> $allKeys
     */
    private function reportMissing(array $catalogs, array $allKeys): int
    {
        $rows = [];

        foreach ($catalogs as $locale => $catalog) {
            foreach ($allKeys as $key) {
                if (!array_key_exists($key, $catalog)) {
                    $rows[] = [$locale, $key];
                }
            }
        }

        $this->section('Eksik anahtarlar');
        $this->rowsOrOk($rows, ['dil', 'anahtar'], 'Her dilde tüm anahtarlar var');

        return count($rows);
    }

    /**
     * @param array<string, array<string, mixed>> $catalogs
     * @param list<string> $allKeys
     */
    private function reportPlaceholders(array $catalogs, array $allKeys): int
    {
        $rows = [];

        foreach ($allKeys as $key) {
            $sets = [];

            foreach ($catalogs as $locale => $catalog) {
                if (is_string($catalog[$key] ?? null)) {
                    $sets[$locale] = $this->placeholders($catalog[$key]);
                }
            }

            $distinct = array_unique(array_map(static fn(array $set): string => implode(',', $set), $sets));

            if (count($distinct) > 1) {
                foreach ($sets as $locale => $set) {
                    $rows[] = [$key, $locale, $set === [] ? '—' : '{' . implode('}, {', $set) . '}'];
                }
            }
        }

        $this->section('Yer tutucu farkları');
        $this->rowsOrOk($rows, ['anahtar', 'dil', 'yer tutucular'], 'Yer tutucular her dilde aynı');

        return count(array_unique(array_column($rows, 0)));
    }

    /**
     * @param array<string, array<string, mixed>> $catalogs
     */
    private function reportSyntax(array $catalogs): int
    {
        $rows = [];

        foreach ($catalogs as $locale => $catalog) {
            foreach ($catalog as $key => $message) {
                if (!is_string($message)) {
                    $rows[] = [$locale, (string) $key, 'metin değil (' . get_debug_type($message) . ')'];
                    continue;
                }

                if (!class_exists(MessageFormatter::class)) {
                    continue;
                }

                try {
                    new MessageFormatter($locale, $message);
                } catch (Throwable $e) {
                    $rows[] = [$locale, (string) $key, $e->getMessage()];
                }
            }
        }

        $this->section('ICU sözdizimi');

        if (!class_exists(MessageFormatter::class)) {
            $this->writeln('  ' . $this->output->yellow('!') . ' intl eklentisi yok — sözdizimi denetlenemedi');
        }

        $this->rowsOrOk($rows, ['dil', 'anahtar', 'hata'], 'Tüm mesajlar geçerli');

        return count($rows);
    }

    /**
     * @param array<string, list<string>> $used
     * @param list<string> $allKeys
     */
    private function reportUndefined(array $used, array $allKeys): int
    {
        $known = array_flip($allKeys);
        $rows = [];

        foreach ($used as $key => $files) {
            if (!isset($known[$key])) {
                $rows[] = [$key, implode(', ', $files)];
            }
        }

        $this->section('Kodda kullanılıp katalogda olmayan');
        $this->rowsOrOk($rows, ['anahtar', 'dosya'], 'Koddaki tüm sabit anahtarlar katalogda var');

        return count($rows);
    }

    /**
     * @param array<string, list<string>> $used
     * @param list<string> $allKeys
     */
    private function reportUnused(array $used, array $allKeys): void
    {
        $unused = array_values(array_filter($allKeys, static fn(string $key): bool => !isset($used[$key])));

        $this->section('Kodda sabit olarak geçmeyenler (' . count($unused) . ')');
        $this->writeln('  ' . $this->output->gray(
            'Bilgi amaçlı: çalışma anında üretilen anahtarlar (ör. validation.<kural>) da burada görünür.'
        ));

        if ($unused !== []) {
            $this->newLine();
            $this->listing($unused);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function keysUsedInCode(): array
    {
        $used = [];
        $root = rtrim($this->app->root, '/\\');

        foreach (['app', 'system'] as $dir) {
            $path = $this->app->path($dir);

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                $name = str_replace('\\', '/', $file->getPathname());

                if ($file->getExtension() !== 'php'
                    || str_contains($name, '/system/cache/')
                    || str_contains($name, '/system/console/')
                    || str_contains($name, '/lang/')
                ) {
                    continue;
                }

                if (preg_match_all(self::TRANS_CALL, (string) file_get_contents($file->getPathname()), $matches) === 0) {
                    continue;
                }

                $relative = ltrim(substr($name, strlen(str_replace('\\', '/', $root))), '/');

                foreach (array_unique($matches[1]) as $key) {
                    $used[$key][] = $relative;
                }
            }
        }

        ksort($used);

        return $used;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $message): array
    {
        $names = [];
        $depth = 0;
        $length = strlen($message);

        for ($i = 0; $i < $length; $i++) {
            $char = $message[$i];

            if ($char === "'") {
                $close = strpos($message, "'", $i + 1);
                $i = $close === false ? $length : $close;
                continue;
            }

            if ($char === '{') {
                if ($depth === 0 && preg_match('/\G\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*[,}]/', $message, $match, 0, $i) === 1) {
                    $names[] = $match[1];
                }
                $depth++;
            } elseif ($char === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param list<list<string>> $rows
     * @param list<string> $headers
     */
    private function rowsOrOk(array $rows, array $headers, string $ok): void
    {
        if ($rows === []) {
            $this->writeln('  ' . $this->output->green('✓') . ' ' . $ok);

            return;
        }

        $this->table($headers, $rows);
    }
}
