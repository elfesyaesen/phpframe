<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Container\Compiled\CompiledContainerLoader;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Input\InputArgument;
use System\Routing\Cache\ApcuRouteCache;
use System\Routing\Cache\FileRouteCache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

#[AsCommand(
    name: 'cache:clear',
    description: 'Twig ve Route cache dosyalarını temizler',
    usage: 'php frame cache:clear [type]',
    aliases: ['cc']
)]
final class CacheClearCommand extends Command
{
    /**
     * Yapılandırma typed config'ten okunur (DI-plan §25).
     */
    public function __construct(
        private readonly AppConfig $app,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // cache:clear yalnızca yeniden üretilebilir cache'leri temizler; onay/force gerekmez.
        $this->addArgument('type', InputArgument::OPTIONAL, 'Cache tipi: all, twig, route, container', 'all');
    }

    protected function handle(): ExitCode
    {
        $type = $this->argument('type', 'all');

        $this->title('Cache Temizleme');

        $success = match ($type) {
            'twig' => $this->clearTwigCache(),
            'route' => $this->clearRouteCache(),
            'container' => $this->clearContainerGraph(),
            default => $this->clearTwigCache() && $this->clearRouteCache() && $this->clearContainerGraph(),
        };

        $this->newLine();

        if ($success) {
            $this->success('Tamamlandı!');
        } else {
            $this->warning('Bazı işlemler başarısız oldu.');
        }

        return $success ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }

    private function clearTwigCache(): bool
    {
        $path = APP_ROOT . '/system/cache/twig';

        if (!is_dir($path)) {
            $this->writeln('  ' . $this->output->cyan('ℹ') . ' Twig cache dizini mevcut değil');
            return true;
        }

        $count = $this->deleteDirectory($path, false);

        if ($count >= 0) {
            $this->writeln('  ' . $this->output->green('✓') . " Twig cache temizlendi ({$count} dosya)");
            return true;
        }

        $this->error('Twig cache temizlenemedi');
        return false;
    }

    private function clearRouteCache(): bool
    {
        $success = true;

        // File cache
        $path = APP_ROOT . '/system/cache/router';
        if (is_dir($path)) {
            $fileCache = new FileRouteCache($path);
            $fileCache->clear();
            $this->writeln('  ' . $this->output->green('✓') . ' Route cache temizlendi (File)');
        } else {
            $this->writeln('  ' . $this->output->cyan('ℹ') . ' Route cache dizini mevcut değil');
        }

        // APCu cache
        if ($this->isApcuAvailable()) {
            $apcuCache = new ApcuRouteCache();
            $apcuCache->clear();
            $this->writeln('  ' . $this->output->green('✓') . ' Route cache temizlendi (APCu)');
        } else {
            $this->writeln('  ' . $this->output->gray('-') . ' APCu kullanılamıyor');
        }

        // OPcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $this->writeln('  ' . $this->output->green('✓') . ' OPcache temizlendi');
        }

        return $success;
    }

    /**
     * Derlenmiş container'ı temizler.
     *
     * Eski `graph.cache.php` tek dosyaydı; yeni derleme üç dosya üretir
     * (sınıf + metadata + pointer) ve önceki derlemeler rollback için
     * diskte kalır. Hepsi silinir — `container:compile` yeniden üretir.
     *
     * PRODUCTION UYARISI: production'da bu, boot'u kıran bir işlemdir.
     * Derlenmiş container olmadan Kernel hata verir (sessiz reflection
     * fallback YOKTUR, DI-plan §4). Bu yüzden production'da onay istenir.
     */
    private function clearContainerGraph(): bool
    {
        $dir = CompiledContainerLoader::defaultCacheDir(APP_ROOT);

        $files = [
            ...(glob($dir . '/CompiledContainer.*.php') ?: []),
            ...(glob($dir . '/metadata.*.php') ?: []),
            ...(is_file($dir . '/container.php') ? [$dir . '/container.php'] : []),
        ];

        if ($files === []) {
            $this->writeln('  ' . $this->output->cyan('ℹ') . ' Derlenmiş container mevcut değil');
            return true;
        }

        if ($this->app->production && !$this->confirm(
            'Production modundasınız. Derlenmiş container silinirse uygulama '
            . 'yeniden derlenene kadar BOOT EDEMEZ. Devam?',
            false
        )) {
            $this->writeln('  ' . $this->output->gray('- Derlenmiş container korundu'));
            return true;
        }

        $removed = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $removed++;
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file, true);
                }
            }
        }

        if ($removed !== count($files)) {
            $this->error('Derlenmiş container tamamen temizlenemedi');
            return false;
        }

        $this->writeln(
            '  ' . $this->output->green('✓') . " Derlenmiş container temizlendi ({$removed} dosya)"
        );
        $this->writeln('  ' . $this->output->gray('→ yeniden üretmek için: php frame container:compile'));

        return true;
    }

    private function deleteDirectory(string $dir, bool $removeDir = true): int
    {
        if (!is_dir($dir)) {
            return -1;
        }

        $count = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                @rmdir($path);
            } elseif (@unlink($path)) {
                $count++;
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($path, true);
                }
            }
        }

        if ($removeDir) {
            @rmdir($dir);
        }

        return $count;
    }

    private function isApcuAvailable(): bool
    {
        return function_exists('apcu_store')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }
}
