<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Config\MonitorConfig;
use System\Routing\RouterFactory;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;

#[AsCommand(
    name: 'cache:warm',
    description: 'Route cache dosyalarini yeniden olusturur',
    usage: 'php frame cache:warm',
    aliases: ['cw']
)]
final class CacheWarmCommand extends Command
{
    /**
     * Yapılandırma global sabitlerden değil enjekte edilen typed config'ten okunur (DI-plan §25). Komutlar artık container tarafından çözüldüğü için constructor injection kullanılabiliyor.
     */
    public function __construct(
        private readonly AppConfig $app,
        // `Router` DEĞİL `RouterFactory`: container'daki singleton router
        // `enableCache: production` ile kurulur ve dev/CI'da hiçbir şey
        // yazmayan NullRouteCache kullanır. Bu komutun tek işi cache
        // üretmek, dolayısıyla sürücü açıkça seçilmek zorunda.
        private readonly RouterFactory $routerFactory,
        private readonly MonitorConfig $monitorConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            description: 'APP_PRODUCTION=false olsa da route cache üretir (CI artifact ön-derlemesi)'
        );

    }

    protected function handle(): ExitCode
    {
        $this->title('Cache Olusturma');

        if (!$this->app->production && !$this->option('force')) {
            $this->warning(
                'APP_PRODUCTION=false — route cache devre disi (NullRouteCache). '
                . 'Once .env icinde APP_PRODUCTION=true yap.'
            );
            return ExitCode::FAILURE;
        }

        $cacheFile = APP_ROOT . '/system/cache/router/routes.cache.php';

        // Dosyanın GERÇEKTEN yazıldığını doğrulamak için önceki durumu al.
        // Yalnızca `file_exists()` bakmak yetmez: önceki bir koşudan kalan
        // dosya, hiçbir şey yazılmamış olsa bile "başarılı" görünmesine yol
        // açıyordu (bu komut --force ile dev'de çalıştırıldığında tam olarak
        // böyle oluyordu).
        $before = is_file($cacheFile) ? (int) filemtime($cacheFile) : 0;
        @unlink($cacheFile);

        // Router'ı CACHE AÇIK kurarız — container'daki singleton router
        // `enableCache: production` ile kurulduğu için dev'de NullRouteCache
        // kullanır ve hiçbir şey yazmaz. Bu komutun tek işi cache üretmek,
        // dolayısıyla sürücü burada açıkça seçilir.
        $router = $this->routerFactory->create(enableCache: true);
        $monitorConfig = $this->monitorConfig;
        require_once APP_ROOT . '/routes/routes.php';

        // Cache yazımını TETİKLE ve gerçek route sayısını al: ağaç kurulumu
        // attribute taramasını ve route dosyasını kapsar, dolayısıyla bu
        // çağrı olmadan cache dosyası hiç oluşmaz.
        $count = $router->warmCache();

        if (!is_file($cacheFile)) {
            $this->error(
                'Route cache yazilamadi: ' . $cacheFile . "\n"
                . '  Dizin yazılabilir mi? (system/cache/router)'
            );

            return ExitCode::FAILURE;
        }

        $size = filesize($cacheFile);
        $fresh = (int) filemtime($cacheFile) !== $before;

        $this->writeln(
            '  ' . $this->output->green('✓')
            . " Route cache olusturuldu ({$count} route, " . $this->formatBytes($size) . ')'
            . ($fresh ? '' : $this->output->yellow('  [DİKKAT: dosya güncellenmedi]'))
        );
        $this->writeln('  ' . $this->output->gray('→') . " {$cacheFile}");

        $this->newLine();
        $this->success('Tamamlandi!');

        return ExitCode::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
