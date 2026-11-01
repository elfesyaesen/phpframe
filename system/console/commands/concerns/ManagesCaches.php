<?php

declare(strict_types=1);

namespace System\Console\Commands\Concerns;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use System\Config\MonitorConfig;
use System\Engine\ModuleRegistry;
use System\Engine\TwigFactory;
use System\Engine\ViewPathRegistry;
use System\Routing\Cache\ApcuRouteCache;
use System\Routing\Cache\FileRouteCache;
use System\Routing\RouterFactory;
use Throwable;
use Twig\Loader\FilesystemLoader;

trait ManagesCaches
{
    private function allowCacheBuild(): bool
    {
        if ($this->app->production || $this->option('force')) {
            return true;
        }

        $this->warning(
            'APP_PRODUCTION=false — cache\'ler bu ortamda kullanılmaz '
            . '(dev her istekte route\'ları tarar, şablonları derler).' . "\n"
            . '  Yine de üretmek için (CI artifact ön-üretimi): --force'
        );

        return false;
    }

    private function routeCacheFile(): string
    {
        return $this->app->cachePath('router', 'routes.cache.php');
    }

    /**
     * @param MonitorConfig $monitorConfig
     */
    private function buildRouteCache(RouterFactory $routerFactory, MonitorConfig $monitorConfig): bool
    {
        $this->section('Route cache');

        try {
            $cacheFile = $this->routeCacheFile();

            @unlink($cacheFile);

            $router = $routerFactory->create(
                cache: new FileRouteCache($routerFactory->cachePath()),
                enableCache: true,
            );
            require APP_ROOT . '/routes/routes.php';

            $count = $router->warmCache();

            if (!is_file($cacheFile)) {
                $this->error('Route cache yazılamadı: ' . $cacheFile . "\n" . '  Dizin yazılabilir mi? (storage/cache/router)');

                return false;
            }

            $this->writeln(sprintf(
                '  %s %d route cache\'lendi (%s)',
                $this->output->green('✓'),
                $count,
                $this->formatBytes((int) filesize($cacheFile)),
            ));
            $this->writeln('  ' . $this->output->gray('→ ' . $cacheFile));

            return true;
        } catch (Throwable $e) {
            $this->error('Route cache hatası: ' . $e->getMessage());

            return false;
        }
    }

    private function clearRouteCache(): bool
    {
        $this->section('Route cache');

        $path = $this->app->cachePath('router');
        $success = true;

        if (is_dir($path)) {
            (new FileRouteCache($path))->clear();

            if (glob($path . '/routes.*.php') === []) {
                $this->writeln('  ' . $this->output->green('✓') . ' Route cache temizlendi (dosya)');
            } else {
                $this->error('Route cache dosyası silinemedi: ' . $path);
                $success = false;
            }
        } else {
            $this->writeln('  ' . $this->output->gray('-') . ' Route cache dizini yok');
        }

        if (ApcuRouteCache::isAvailable()) {
            (new ApcuRouteCache())->clear();
            $this->writeln('  ' . $this->output->green('✓') . ' Route cache temizlendi (CLI APCu)');
        }

        return $success;
    }

    private function twigCacheDir(): string
    {
        return $this->app->cachePath('twig');
    }

    private function buildTwigCache(): bool
    {
        $this->section('Twig cache');

        try {
            $registry = new ViewPathRegistry(new FilesystemLoader([], $this->app->root));
            $templates = [];

            foreach (ModuleRegistry::discover($this->app->root) as $module) {
                $dir = $this->app->path(ModuleRegistry::DIR, $module, 'views');

                if (!is_dir($dir)) {
                    continue;
                }

                $registry->add($dir, $module);

                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($files as $file) {
                    if ($file->isFile() && $file->getExtension() === 'twig') {
                        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                        $templates[] = '@' . $module . '/' . $relative;
                    }
                }
            }

            sort($templates);

            if ($templates === []) {
                $this->writeln('  ' . $this->output->gray('-') . ' Modüllerde şablon yok');

                return true;
            }

            $twig = (new TwigFactory($this->app, $registry))->create(production: true);
            $failed = 0;

            foreach ($templates as $name) {
                try {
                    $twig->load($name);
                } catch (Throwable $e) {
                    $failed++;
                    $this->error($name . ': ' . $e->getMessage());
                }
            }

            $compiled = count($templates) - $failed;
            $this->writeln(sprintf(
                '  %s %d şablon derlendi%s',
                $failed === 0 ? $this->output->green('✓') : $this->output->yellow('!'),
                $compiled,
                $failed === 0 ? '' : ", {$failed} hatalı",
            ));
            $this->writeln('  ' . $this->output->gray('→ ' . $this->twigCacheDir()));

            return $failed === 0;
        } catch (Throwable $e) {
            $this->error('Twig cache hatası: ' . $e->getMessage());

            return false;
        }
    }

    private function clearTwigCache(): bool
    {
        $this->section('Twig cache');

        $dir = $this->twigCacheDir();

        if (!is_dir($dir)) {
            $this->writeln('  ' . $this->output->gray('-') . ' Twig cache dizini yok');

            return true;
        }

        [$count, $failed] = $this->deleteDirectoryContents($dir);

        if ($failed === 0) {
            $this->writeln('  ' . $this->output->green('✓') . " Twig cache temizlendi ({$count} dosya)");

            return true;
        }

        $this->error("Twig cache tamamen temizlenemedi ({$failed} dosya silinemedi)");

        return false;
    }

    private function clearLegacyContainerCache(): bool
    {
        $dir = $this->app->path('system', 'cache', 'container');

        $files = [
            ...(glob($dir . '/CompiledContainer.*.php') ?: []),
            ...(glob($dir . '/metadata.*.php') ?: []),
            ...(is_file($dir . '/container.php') ? [$dir . '/container.php'] : []),
        ];

        if ($files === []) {
            return true;
        }

        $this->section('Eski container cache');

        $failed = 0;
        foreach ($files as $file) {
            if (!@unlink($file)) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->error("Eski container dosyaları tamamen silinemedi ({$failed} dosya)");

            return false;
        }

        $this->writeln('  ' . $this->output->green('✓') . ' ' . count($files) . ' eski dosya silindi');

        return true;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function deleteDirectoryContents(string $dir): array
    {
        $count = 0;
        $failed = 0;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getPathname();

            if ($item->getFilename() === '.gitkeep') {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($path);
            } elseif (@unlink($path)) {
                $count++;

                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($path, true);
                }
            } else {
                $failed++;
            }
        }

        return [$count, $failed];
    }

    private function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => $bytes . ' B',
            $bytes < 1024 * 1024 => round($bytes / 1024, 1) . ' KB',
            default => round($bytes / 1024 / 1024, 1) . ' MB',
        };
    }

    /**
     * @param list<callable(): bool> $steps
     */
    private function runCacheSteps(array $steps, string $done): \System\Console\ExitCode
    {
        $success = true;

        foreach ($steps as $step) {
            $success = $step() && $success;
        }

        $this->newLine();

        if (!$success) {
            $this->error('Bazı adımlar başarısız oldu.');

            return \System\Console\ExitCode::FAILURE;
        }

        $this->success($done);

        return \System\Console\ExitCode::SUCCESS;
    }
}
