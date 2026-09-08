<?php

declare(strict_types=1);

namespace System\Runtime;

use System\Config\AppConfig;
use System\Config\MonitorConfig;
use System\Container\Compiled\CompiledContainerLoader;
use System\Container\Contract\BootableProviderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Contract\ScopeInterface;
use System\Container\Contract\ServiceProviderInterface;
use System\Container\Core\ContainerBuilder;
use System\Container\Exception\CompilationException;
use Throwable;

/**
 * Uygulama çekirdeği: config kurar, container'ı build veya load eder,
 * provider'ları boot eder, scope yönetir.
 *
 * DEV / PRODUCTION ASİMETRİSİ (DI-plan §4, §38 — "development'ta akıllı,
 * production'da basit"):
 *
 *   DEV        → ContainerBuilder + provider'lar + reflection + validate()
 *                Kod değişiklikleri anında yansır.
 *   PRODUCTION → TEK bir üretilmiş dosya require edilir. Reflection yok,
 *                autowiring yok, graf yürüyüşü yok, provider register() yok.
 *
 * Production'da provider sınıfları yalnızca `boot()` için yüklenir — yan
 * etkiler derlenemez, dolayısıyla runtime'da çalışmak zorundadır.
 */
final class Kernel
{
    private ?ScopeInterface $requestScope = null;

    private function __construct(
        private readonly ContainerInterface $container,
        private readonly ShutdownRegistry $shutdown,
        private readonly AppConfig $app,
        private readonly Sapi $sapi,
    ) {}

    /**
     * Çekirdeği kurar: config → container → lock → boot.
     */
    public static function create(Sapi $sapi, string $appRoot): self
    {
        $shutdown = new ShutdownRegistry();

        // Config objeleri container'dan ÖNCE kurulur: grafiğin girdisidir,
        // ürünü değil. Production'da doğrulama burada koşar ve eksik/zayıf
        // anahtarlar tek yerde, container kurulmadan yakalanır.
        //
        // Küme RuntimeInstances'tan gelir — CLI derleyicisi de aynı yerden
        // alır, böylece "derlenen == koşan" id kümesi ayrışamaz.
        try {
            $instances = RuntimeInstances::build($appRoot, $shutdown, validate: true);
        } catch (Throwable $e) {
            self::fail($e->getMessage());
        }

        /** @var AppConfig $app */
        $app = $instances[AppConfig::class];

        // TANIMLAR için TÜM provider'lar (SAPI filtresi YOK) — derlenmiş
        // container tek olduğu ve hem HTTP hem CLI'ya hizmet ettiği için
        // tanım kümesi birleşim olmak zorunda. Ayrıntılı gerekçe:
        // ContainerBuilder::instantiateProviders() docblock'u.
        $providers = self::providers($appRoot, null);

        $container = $app->production
            ? self::compiled($appRoot, $instances)
            : self::built($instances, $providers);

        $kernel = new self($container, $shutdown, $app, $sapi);

        // YAN ETKİLER için SAPI filtresi UYGULANIR: bir tanım inerttir ama
        // `ob_start()` veya shutdown hook kaydı değildir. CLI'da monitor'ün
        // çıktı tamponunu açmasının anlamı yok.
        //
        // Tanımların TAMAMI hazır olduktan SONRA çalışır — eski bootstrap'ın
        // "binding henüz kaydedilmemişti" sınıfı hataları temsil edilemez.
        foreach ($providers as $provider) {
            if ($provider instanceof BootableProviderInterface && $provider->supports($sapi)) {
                $provider->boot($container);
            }
        }

        return $kernel;
    }

    /**
     * Dev: provider'lardan build + doğrula.
     */
    private static function built(
        array $instances,
        array $providers,
    ): ContainerInterface {
        $builder = new ContainerBuilder();

        foreach ($instances as $id => $value) {
            $builder->instance($id, $value);
        }

        foreach ($providers as $provider) {
            $provider->register($builder);
        }

        return $builder->lock()->build();
    }

    /**
     * Production: derlenmiş container'ı yükle.
     *
     * Eksik veya bayat derleme HARD FATAL'dır — sessiz reflection fallback
     * YOKTUR (DI-plan §4, §32). Eski `Container::reflectAndBuild()` yalnızca
     * uyarı logluyordu; bir servisin derlemeden düşmesinin haftalarca fark
     * edilmemesinin yolu buydu.
     */
    private static function compiled(string $appRoot, array $instances): ContainerInterface
    {
        try {
            return CompiledContainerLoader::forRoot($appRoot)->load($instances);
        } catch (CompilationException $e) {
            self::fail($e->getMessage());
        }
    }

    /**
     * @return list<ServiceProviderInterface>
     */
    private static function providers(string $appRoot, ?Sapi $sapi): array
    {
        try {
            return ContainerBuilder::instantiateProviders($appRoot, $sapi);
        } catch (Throwable $e) {
            self::fail($e->getMessage());
        }
    }

    // ── Scope yönetimi ─────────────────────────────────────────────

    /**
     * İstek scope'unu açar ve `RequestSnapshot`'ı EAGER yerleştirir.
     *
     * Snapshot'ın eager olması ZORUNLU: `php://input` PHP'de tek kez
     * okunabilir bir akıştır. Router veya Request onu tükettikten sonra
     * monitor ham gövdeyi bir daha elde edemez. Lazy olsaydı monitor'ün
     * gövdesi bazen dolu bazen boş olurdu — hangi kod yolunun input'u önce
     * okuduğuna bağlı olarak.
     *
     * Ayrıca scope teardown EN DÜŞÜK öncelikli shutdown hook'u olarak
     * kaydedilir: monitor'ün shutdown-zamanı collector'ları scoped
     * servisleri (RequestContext, Recorder) hâlâ çözebilmeli.
     */
    public function beginRequestScope(): ScopeInterface
    {
        if ($this->requestScope !== null) {
            return $this->requestScope;
        }

        $scope = $this->container->createScope($this->sapi->value);
        $this->requestScope = $scope;

        $scope->set(RequestSnapshot::class, $this->captureSnapshot());

        $this->sendRequestIdHeader($scope);

        // Arrow function DEĞİL: `fn(): void => ...` ifade değeri döndürmeye
        // çalıştığı için void dönüşle çakışır.
        $this->shutdown->on(
            ShutdownPriority::SCOPE_DISPOSE,
            static function () use ($scope): void {
                $scope->dispose();
            },
        );

        return $scope;
    }

    /**
     * `X-Request-ID` yanıt başlığı.
     *
     * NEDEN BURADA, LoggingProvider::boot() İÇİNDE DEĞİL: kimlik scope'a
     * bağlıdır (SCOPED servis) ama `boot()` scope açılmadan çalışır. Ayrıca
     * başlık, herhangi bir gövde yazılmadan önce gönderilmek zorunda —
     * scope'un açıldığı an tam olarak o nokta.
     *
     * Bu kimlik aynı zamanda monitor kaydının birincil anahtarıdır
     * (monitor_request.request_id) ve tüm log satırlarında context olarak
     * yer alır; dolayısıyla dashboard'daki bir istekten logs/app.log
     * satırlarına geçilebilir.
     */
    private function sendRequestIdHeader(ScopeInterface $scope): void
    {
        if ($this->sapi->isCli() || headers_sent()) {
            return;
        }

        header('X-Request-ID: ' . $scope->get(RequestId::class)->value);
    }

    private function captureSnapshot(): RequestSnapshot
    {
        if ($this->sapi->isCli()) {
            return RequestSnapshot::forCli();
        }

        $monitor = $this->container->get(MonitorConfig::class);

        return RequestSnapshot::capture(
            server: $_SERVER,
            bufferingResponse: $monitor->enabled && $monitor->captureResponseBody,
        );
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    public function shutdown(): ShutdownRegistry
    {
        return $this->shutdown;
    }

    public function app(): AppConfig
    {
        return $this->app;
    }

    public function sapi(): Sapi
    {
        return $this->sapi;
    }

    /**
     * Boot-zamanı ölümcül hata: yapılandırma veya derleme sorunu.
     *
     * İstisna fırlatmak yerine doğrudan çıkılır çünkü bu noktada henüz
     * logger ve exception handler kurulmuş olmayabilir — istisna, PHP'nin
     * varsayılan hata çıktısına düşer ve production'da yığın izi sızdırabilir.
     *
     * @return never
     */
    private static function fail(string $message): never
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }

        exit($message . PHP_EOL);
    }
}
