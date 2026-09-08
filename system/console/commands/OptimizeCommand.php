<?php

declare(strict_types=1);

namespace System\Console\Commands;

use System\Config\AppConfig;
use System\Config\MonitorConfig;
use System\Routing\RouterFactory;
use System\Console\Attributes\Command as AsCommand;
use System\Console\Command;
use System\Console\ExitCode;
use System\Console\Commands\Concerns\BuildsContainer;
use System\Container\Compilation\AtomicWriter;
use System\Container\Compiled\CompiledContainerLoader;

/**
 * Üretim derleme adımlarını tek komutta toplar (deploy hattı).
 *
 * Deploy hattının "build" adımı:
 *   container:compile  → DI bağımlılık grafiği (runtime reflection'sız O(1))
 *   cache:warm         → radix-tree route cache
 *
 * Not: OPcache reset / FPM reload bu komutun kapsamı dışındadır; deploy script'i
 * (`systemctl reload php8.5-fpm`) ile yapılır — çünkü CLI süreci, çalışan FPM
 * process'lerinin paylaşılan OPcache'ini etkilemez.
 */
#[AsCommand(
    name: 'optimize',
    description: 'Container graph + route cache derler (production deploy build adımı)',
    usage: 'php frame optimize',
    aliases: ['opt']
)]
final class OptimizeCommand extends Command
{
    use BuildsContainer;

    /**
     * Yapılandırma typed config'ten okunur (DI-plan §25). Komutlar container
     * tarafından çözüldüğü için constructor injection kullanılabiliyor.
     *
     * `Router` DEĞİL `RouterFactory` enjekte edilir: container'daki singleton
     * router `enableCache: production` ile kurulur ve dev/CI'da hiçbir şey
     * yazmayan NullRouteCache kullanır. Bu komutun tek işi cache üretmek,
     * dolayısıyla sürücü açıkça seçilmek zorunda.
     */
    public function __construct(
        private readonly AppConfig $app,
        private readonly RouterFactory $routerFactory,
        private readonly MonitorConfig $monitorConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'ci',
            null,
            description: 'Kaynak parmak izinde mtime yerine içerik hash\'i kullanır — CI\'da ZORUNLU'
        );

        $this->addOption(
            'prune',
            null,
            description: 'Eski derlemeleri temizler (bir önceki rollback için kalır)'
        );

        $this->addOption(
            'force',
            'f',
            description: 'APP_PRODUCTION=false olsa da derler (CI\'da artifact ön-derleme)'
        );
    }

    protected function handle(): ExitCode
    {
        $this->title('Production Optimizasyonu');

        if (!$this->app->production && !$this->option('force')) {
            // Varsayılan olarak bloklanır: dev'de derlemek çoğu zaman bir
            // yanlış anlamadır ("neden değişikliğim görünmüyor?" — çünkü dev
            // derlenmiş çıktıyı YOK SAYAR, kod anında yansır).
            //
            // Ama --force ile derlemek MEŞRUDUR ve CI için gerekir:
            // derlenmiş container ORTAMDAN BAĞIMSIZDIR. Typed config
            // objeleri `instance()` ile external olarak girdiği için
            // (DI-plan §25) hiçbir env değeri üretilen dosyaya gömülmez —
            // dolayısıyla nötr bir CI ortamında derlenen artifact,
            // production'da aynen geçerlidir.
            $this->warning(
                'APP_PRODUCTION=false — derlenmiş cache\'ler bu ortamda '
                . 'runtime\'da YÜKLENMEZ (dev reflection kullanır).' . "\n"
                . '  Yine de derlemek için (CI artifact ön-derlemesi): '
                . 'php frame optimize --force'
            );

            return ExitCode::FAILURE;
        }

        $ok = $this->compileContainer() && $this->warmRouteCache();

        $this->newLine();

        if (!$ok) {
            $this->error('Optimizasyon tamamlanamadı.');
            return ExitCode::FAILURE;
        }

        $this->success('Optimizasyon tamamlandı.');
        $this->note('Değişikliklerin canlıya yansıması için: sudo systemctl reload php8.5-fpm');

        return ExitCode::SUCCESS;
    }

    private function compileContainer(): bool
    {
        $this->section('1/2 · Container graph');

        $ci = (bool) $this->option('ci');

        try {
            // --ci: kaynak parmak izi mtime yerine sha1_file kullanır.
            // CI'da ZORUNLU — mtime taze bir git checkout'ta tekrarlanabilir
            // değildir, dolayısıyla her koşu farklı hash üretir ve
            // `container:validate --check` kapısı yalancı pozitif verir.
            $result = $this->compiler(useContentHash: $ci)->compile();

            // Doğrulama bulguları derlemeyi bloklar: eksik binding, döngü,
            // scope ihlali veya derlenemez factory varsa deploy build'i
            // BURADA durmalı — production'da ilk istekte değil.
            if (!$result->isSuccessful()) {
                $this->error(
                    'Container derlenemedi: ' . count($result->errors()) . ' hata.' . "\n"
                    . '  Ayrıntı için: php frame container:validate'
                );

                return false;
            }

            if ($result->source === null || $result->className === null) {
                $this->error('Container kod üretimi başarısız.');

                return false;
            }

            $writer = new AtomicWriter(CompiledContainerLoader::defaultCacheDir(APP_ROOT));

            $paths = $writer->write(
                $result->className,
                $result->hash,
                $result->source,
                $result->toMetadata(),
                // Bayatlık kontrolünün AYNI modla karşılaştırabilmesi için
                // hash'in hangi modla üretildiği pointer'a yazılır.
                $ci ? AtomicWriter::HASH_MODE_CONTENT : AtomicWriter::HASH_MODE_MTIME
            );

            if ((bool) $this->option('prune')) {
                // keep: 1 → canlı derleme + bir önceki kalır. Önceki,
                // pointer'ı geri çevirerek anında rollback için gerekli.
                $removed = $writer->prune(keep: 1);
                $this->writeln('  ' . $this->output->gray(
                    '✓ eski derlemeler temizlendi (' . count($removed) . ' dosya)'
                ));
            }

            $this->writeln(sprintf(
                '  %s %d servis derlendi (%s ms), hash %s',
                $this->output->green('✓'),
                $result->serviceCount(),
                number_format((float) ($result->stats['ms_total'] ?? 0), 1),
                substr($result->hash, 0, 12) . '…'
            ));
            $this->writeln('  ' . $this->output->gray('→ ' . $paths['class']));

            return true;
        } catch (\Throwable $e) {
            $this->error('Container derleme hatası: ' . $e->getMessage());
            return false;
        }
    }

    private function warmRouteCache(): bool
    {
        $this->section('2/2 · Route cache');

        try {
            $cacheFile = APP_ROOT . '/system/cache/router/routes.cache.php';

            // Dosyanın GERÇEKTEN yazıldığını doğrulamak için önceki durumu
            // al. Yalnızca `is_file()` bakmak yetmez: önceki bir koşudan
            // kalan dosya, hiçbir şey yazılmamış olsa bile "başarılı"
            // görünmesine yol açıyordu.
            $before = is_file($cacheFile) ? (int) filemtime($cacheFile) : 0;
            @unlink($cacheFile);

            // Router CACHE AÇIK kurulur. Container'daki singleton router
            // `enableCache: production` ile kurulduğu için dev'de (veya
            // --force ile CI'da) NullRouteCache kullanır ve hiçbir şey
            // yazmaz. Bu adımın tek işi cache üretmek, dolayısıyla sürücü
            // burada açıkça seçilir.
            //
            // Eskiden burada `require_once bootstrap.php` vardı; artık bu
            // İKİNCİ bir Kernel kurmak demek olurdu — çifte boot, çifte
            // shutdown hook, çifte exception handler kaydı.
            $router = $this->routerFactory->create(enableCache: true);
            $monitorConfig = $this->monitorConfig;
            require_once APP_ROOT . '/routes/routes.php';

            // Cache yazımını TETİKLE ve gerçek route sayısını al.
            $count = $router->warmCache();

            if (!is_file($cacheFile)) {
                $this->error(
                    'Route cache yazılamadı: ' . $cacheFile . "\n"
                    . '  Dizin yazılabilir mi? (system/cache/router)'
                );

                return false;
            }

            $fresh = (int) filemtime($cacheFile) !== $before;

            $this->writeln(sprintf(
                '  %s %d route cache\'lendi (%s)%s',
                $this->output->green('✓'),
                $count,
                $this->formatBytes((int) filesize($cacheFile)),
                $fresh ? '' : $this->output->yellow('  [DİKKAT: dosya güncellenmedi]')
            ));
            $this->writeln('  ' . $this->output->gray('→ ' . $cacheFile));
            return true;
        } catch (\Throwable $e) {
            $this->error('Route cache hatası: ' . $e->getMessage());
            return false;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
