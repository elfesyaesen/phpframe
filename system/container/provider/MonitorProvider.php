<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\MonitorConfig;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Lifetime\Lifetime;
use System\Database\Database;
use System\Http\Request;
use System\Logging\Contracts\LoggerInterface;
use System\Logging\LoggerLocator;
use System\Monitor\CollectorSet;
use System\Monitor\Collectors\FatalErrorCollector;
use System\Monitor\Collectors\HttpCollector;
use System\Monitor\Contracts\RecorderInterface;
use System\Monitor\Contracts\SamplerInterface;
use System\Monitor\Contracts\ScrubberInterface;
use System\Monitor\Contracts\StorageInterface;
use System\Monitor\Recorder;
use System\Monitor\RecorderLocator;
use System\Monitor\RequestContext;
use System\Monitor\Sampler\RateSampler;
use System\Monitor\Scrubber\KeyScrubber;
use System\Monitor\Storage\DatabaseStorage;
use System\Monitor\Storage\NullStorage;
use System\Runtime\RequestId;
use System\Runtime\RequestSnapshot;
use System\Runtime\Sapi;
use System\Runtime\ShutdownPriority;
use System\Runtime\ShutdownRegistry;

/**
 * Gözlemlenebilirlik (monitor).
 *
 * Eski `MonitorBootstrapper` SİLİNDİ; üç sorumluluğu ayrıştı:
 *   • ham gövde + başlangıç zamanı → RuntimeProvider'ın RequestSnapshot'ı
 *   • ob_start()                   → bu provider'ın boot()'u
 *   • RequestContext kaydı         → aşağıdaki BİLDİRİMSEL tanım
 *
 * Sonuncusu önemli: `RequestContext` eskiden bir boot metodunun İÇİNDEN
 * imperative olarak `$container->singleton(...)` ile kaydediliyordu. Bu, hem
 * derlenemez (tanım runtime'da oluşuyor) hem de doğrulanamazdı.
 */
final class MonitorProvider extends AbstractServiceProvider implements BootableProviderInterface
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // ── Stateless yardımcılar: singleton ───────────────────────
        $builder->singleton(ScrubberInterface::class, KeyScrubber::class);

        $builder->factory(
            SamplerInterface::class,
            [self::class, 'sampler'],
            Lifetime::SINGLETON,
            dependsOn: [MonitorConfig::class],
        );

        $builder->factory(
            StorageInterface::class,
            [self::class, 'storage'],
            Lifetime::SINGLETON,
            dependsOn: [MonitorConfig::class, LoggerLocator::class],
        );

        // NOT — `cutEdge()` bildirimi KASITLI OLARAK YOK.
        //
        // Eskiden burada Storage → Database kenarı kesik olarak bildiriliyordu,
        // çünkü Database doğrudan Recorder alıyor ve
        // Database → Recorder → Storage → Database döngüsü oluşuyordu.
        // `RecorderLocator` o kenarı tamamen ortadan kaldırdı, dolayısıyla
        // GRAFİKTE artık döngü yok ve bildirim ölü kalırdı
        // (`container:validate` bunu CYCLE_CUT_OBSOLETE olarak raporlar).
        //
        // RUNTIME'da hâlâ bir çevrim var:
        //   Storage → Database → (enstrümante PDO) → RecorderLocator → Recorder → Storage
        // Bu, `Recorder::flush()`'ın idempotent olması ve locator'ın scope
        // yokken sessizce hiçbir şey yapmaması sayesinde sonsuza gitmez.
        // Derleyici locator'ın içini göremez, ama koruma artık bildirimde
        // değil SCOPE DOĞRULAMASINDA: biri RecorderLocator'ı kaldırıp
        // Recorder'ı doğrudan enjekte ederse SINGLETON_CAPTURES_SCOPED hatası
        // alır.
        //
        // Bağlantının lazy alınması (aşağıdaki `databaseProvider` closure'ı)
        // yine de korunur: Storage kurulurken DB'ye bağlanmak, monitor kapalı
        // olsa bile bağlantı açardı.

        // ── Per-request durum: SCOPED ──────────────────────────────
        //
        // Recorder RequestTrace, sorgu sayaçları ve flush durumu tutar —
        // hepsi per-request mutable state. Singleton olsaydı bir worker'ın
        // gördüğü tüm isteklerin sorguları aynı trace'e karışırdı.
        $builder->factory(
            RecorderInterface::class,
            [self::class, 'recorder'],
            Lifetime::SCOPED,
            dependsOn: [
                StorageInterface::class,
                SamplerInterface::class,
                ScrubberInterface::class,
                LoggerInterface::class,
                MonitorConfig::class,
                RequestId::class,
                // Collector kümesi grafe GİRER: etiketli collector'ların
                // bağımlılıkları böylece scope/döngü doğrulamasına dahil
                // olur. Eskiden liste factory içinde inline kuruluyordu ve
                // collector'ların bağımlılıkları hiç görünmüyordu.
                CollectorSet::class,
            ],
        );

        // Database (singleton) bu LOCATOR'ı alır, Recorder'ı DEĞİL.
        // Böylece Singleton → Scoped kenarı hiç oluşmaz ve her sorgu doğru
        // isteğin trace'ine gider (bkz. RecorderLocator).
        // `dependsOn` KASITLI OLARAK BOŞ.
        //
        // RecorderInterface'i bildirmek, bu singleton'ın scoped bir servise
        // bağımlı olduğunu grafe yazardı ve scope doğrulaması haklı olarak
        // SINGLETON_CAPTURES_SCOPED hatası verirdi. Locator'ın var olma
        // sebebi tam olarak o kenarı KIRMAK: bağımlılık kurulumda değil,
        // her çağrıda aktif scope'tan çözülüyor.
        //
        // `container:validate` bunu FACTORY_UNDECLARED_DEPS bilgisi olarak
        // raporlar — opaklık sessiz kalmaz, görünür ve gerekçesi burada.
        $builder->factory(
            RecorderLocator::class,
            [self::class, 'recorderLocator'],
            Lifetime::SINGLETON,
        );

        $builder->factory(
            RequestContext::class,
            [self::class, 'requestContext'],
            Lifetime::SCOPED,
            dependsOn: [RequestSnapshot::class],
        );

        // Collector'lar scoped: RequestContext ve Request'e bağımlılar.
        //
        // HttpCollector primitive parametreler alıyor (maxBodyBytes,
        // captureRequestBody, captureResponseBody) — autowire edilemez
        // (DI-plan §10). Değerler typed config'ten geldiği için factory
        // kullanılır.
        $builder->factory(
            HttpCollector::class,
            [self::class, 'httpCollector'],
            Lifetime::SCOPED,
            dependsOn: [
                RequestContext::class,
                Request::class,
                ScrubberInterface::class,
                MonitorConfig::class,
            ],
        );

        $builder->scoped(FatalErrorCollector::class);

        // Collector kümesi ETİKETLE bildirilir (DI-plan §20).
        //
        // SIRA ANLAMSAL: FatalErrorCollector, HttpCollector'dan SONRA
        // çalışmak zorunda — durumu 500'e normalize etmesi için önce
        // `http_response_code()` okunmuş olması gerekir. Etiket üye sırası
        // korunur (alfabetik sıralanmaz), bu yüzden burada bildirilen sıra
        // çalışma sırasıdır.
        $builder->tag([
            HttpCollector::class,
            FatalErrorCollector::class,
        ], CollectorSet::TAG);

        $builder->scoped(CollectorSet::class);
    }

    public function provides(): array
    {
        return [
            ScrubberInterface::class,
            SamplerInterface::class,
            StorageInterface::class,
            RecorderInterface::class,
            RecorderLocator::class,
            RequestContext::class,
            HttpCollector::class,
            FatalErrorCollector::class,
            CollectorSet::class,
        ];
    }

    /**
     * Yan etkiler: çıktı tamponu ve shutdown hook'u.
     */
    public function boot(ContainerInterface $container): void
    {
        $config = $container->get(MonitorConfig::class);

        if (!$config->enabled || PHP_SAPI === 'cli') {
            return;
        }

        // Yanıt gövdesi yakalamak ob_start() gerektirir.
        if ($config->captureResponseBody) {
            ob_start();
        }

        // Monitor kaydı EN YÜKSEK öncelikle yazılır: aynı shutdown fonksiyonu
        // içinde sonra çalışacak FATAL_RESPONSE hook'u exit(1) yapar. Sıra
        // burada VERİ (ShutdownPriority), yorumla korunan bir invariant değil.
        $container->get(ShutdownRegistry::class)->on(
            ShutdownPriority::MONITOR_FLUSH,
            static function () use ($container): void {
                $scope = $container->scope();

                if ($scope === null) {
                    return; // scope zaten kapandı; yazılacak istek yok
                }

                $scope->get(RecorderInterface::class)->flush();
            },
        );
    }

    // ── Factory'ler ────────────────────────────────────────────────

    public static function sampler(PsrContainerInterface $container): SamplerInterface
    {
        $config = $container->get(MonitorConfig::class);

        return new RateSampler(
            sampleRate: $config->sampleRate,
            slowMs: $config->slowMs,
            skipPrefixes: $config->skipPrefixes,
        );
    }

    public static function storage(PsrContainerInterface $container): StorageInterface
    {
        $config = $container->get(MonitorConfig::class);

        if (!$config->enabled) {
            return new NullStorage();
        }

        return new DatabaseStorage(
            // Database CLOSURE ile alınır, doğrudan enjekte EDİLMEZ: monitor
            // kendi izlediği veritabanına yazdığı için doğrudan bağımlılık
            // Storage → Database → (enstrümante PDO) → Recorder → Storage
            // döngüsü üretir. Kesik kenar `cutEdge()` ile BİLDİRİLMİŞTİR,
            // dolayısıyla `container:validate` onu görür ve onaylar.
            databaseProvider: static fn(): Database => $container->get(Database::class),
            logger: $container->get(LoggerLocator::class),
        );
    }

    public static function recorder(PsrContainerInterface $container): RecorderInterface
    {
        $config = $container->get(MonitorConfig::class);
        $recording = $config->enabled && PHP_SAPI !== 'cli';

        // Collector listesi artık ETİKETTEN gelir (DI-plan §20) — inline
        // `$container->get()` çağrıları yerine. Kazanç: yeni bir collector
        // eklemek bu factory'yi düzenlemeyi gerektirmez ve collector'ların
        // bağımlılıkları grafe girer (bkz. CollectorSet).
        //
        // Kayıt kapalıyken küme HİÇ ÇÖZÜLMEZ: lazy olduğu için collector'lar
        // kurulmaz, dolayısıyla monitor kapalıyken maliyet yine sıfır.
        $collectors = $recording
            ? $container->get(CollectorSet::class)->all()
            : [];

        return new Recorder(
            storage: $container->get(StorageInterface::class),
            sampler: $container->get(SamplerInterface::class),
            scrubber: $container->get(ScrubberInterface::class),
            logger: $container->get(LoggerInterface::class),
            collectors: $collectors,
            recording: $recording,
            requestId: $container->get(RequestId::class)->value,
            maxQueries: $config->maxQueries,
            nPlusOneThreshold: $config->nPlusOneThreshold,
        );
    }

    public static function recorderLocator(PsrContainerInterface $container): RecorderLocator
    {
        return new RecorderLocator(
            static fn(): RecorderInterface => $container->get(RecorderInterface::class)
        );
    }

    public static function httpCollector(PsrContainerInterface $container): HttpCollector
    {
        $config = $container->get(MonitorConfig::class);

        return new HttpCollector(
            context: $container->get(RequestContext::class),
            request: $container->get(Request::class),
            scrubber: $container->get(ScrubberInterface::class),
            maxBodyBytes: $config->maxBodyBytes,
            captureRequestBody: $config->captureRequestBody,
            captureResponseBody: $config->captureResponseBody,
        );
    }

    public static function requestContext(PsrContainerInterface $container): RequestContext
    {
        $snapshot = $container->get(RequestSnapshot::class);

        return new RequestContext(
            startedAt: $snapshot->startedAt,
            rawInput: $snapshot->rawInput,
            bufferingResponse: $snapshot->bufferingResponse,
        );
    }
}
