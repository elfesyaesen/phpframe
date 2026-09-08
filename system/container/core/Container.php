<?php

declare(strict_types=1);

namespace System\Container\Core;

use System\Container\Binding\BindingRegistry;
use System\Container\Contract\ContainerInterface;
use System\Container\Contract\ScopeInterface;
use System\Container\Definition\ArgumentKind;
use System\Container\Definition\ArgumentRef;
use System\Container\Definition\Definition;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Definition\FactoryKind;
use System\Container\Exception\CircularDependencyException;
use System\Container\Exception\ContainerException;
use System\Container\Exception\FactoryException;
use System\Container\Exception\InvalidScopeException;
use System\Container\Exception\NotFoundException;
use System\Container\Lifetime\Lifetime;
use System\Container\Lifetime\ScopeManager;
use System\Container\Lifetime\SingletonStore;
use System\Container\Observability\ResolutionRecorder;
use System\Container\Resolution\AutowireResolver;

/**
 * Dev (derlenmemiş) runtime container'ı.
 *
 * Production'da bunun yerine üretilmiş CompiledContainer koşar; bu sınıf
 * yüklenmez bile. Buradaki tek görev, DERLENMİŞ container ile AYNI semantiği
 * reflection üzerinden sunmak — böylece kod değişiklikleri anında yansır ama
 * davranış farkı olmaz.
 *
 * Aynılığın mekanizması: argüman çözümlemesi generator ile PAYLAŞILAN
 * ArgumentKind match'i üzerinden yapılır (bkz. resolveArgument()). İki yol
 * aynı 4 kolu işlediği için "dev'de çalıştı, production'da farklı davrandı"
 * sınıfı hatalar yapısal olarak imkânsızdır.
 */
final class Container implements ContainerInterface
{
    public const VERSION = '1.0.0';

    /** @var array<string, true> Şu an çözülmekte olan id'ler (döngü tespiti). */
    private array $resolving = [];

    /**
     * Runtime'da tamamlanmış (argümanları doldurulmuş) tanımlar.
     *
     * @var array<string, Definition>
     */
    private array $completed = [];

    /**
     * @param array<class-string, object> $externals
     */
    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly BindingRegistry $bindings,
        private readonly ?AutowireResolver $autowire,
        private readonly SingletonStore $singletons,
        private readonly ScopeManager $scopes,
        private readonly array $externals = [],
        /**
         * Çözümleme sayaçları (DI-plan §28) — YALNIZCA DEV.
         *
         * Derlenmiş container bu mekanizmayı hiç taşımaz: kapalıyken bile
         * bir `if` bırakmak, her servis çözümlemesine bir dal eklerdi ve
         * §16'nın "lookup = 0" hedefiyle çelişirdi. Burada da null ise
         * hiçbir maliyet yok (`?->` kısa devre yapar).
         */
        private readonly ?ResolutionRecorder $recorder = null,
    ) {}

    public function get(string $id): mixed
    {
        $resolved = $this->bindings->resolve($id);

        $this->recorder?->recordResolution($resolved);

        // Singleton hızlı yol: zincir/koruma kurulmadan önce.
        if ($this->singletons->has($resolved)) {
            return $this->singletons->get($resolved);
        }

        if (isset($this->resolving[$resolved])) {
            $chain = array_keys($this->resolving);
            $chain[] = $resolved;

            throw CircularDependencyException::runtime($chain);
        }

        $definition = $this->definition($resolved);

        return match ($definition->lifetime) {
            Lifetime::INSTANCE => $this->externals[$resolved]
                ?? $this->literalValue($definition),

            Lifetime::SINGLETON => $this->singletons->remember(
                $resolved,
                fn(): mixed => $this->create($definition)
            ),

            Lifetime::SCOPED => ($this->scopes->active()
                ?? throw InvalidScopeException::noActiveScope($resolved, array_keys($this->resolving))
            )->remember($resolved, fn(): mixed => $this->create($definition)),

            Lifetime::TRANSIENT => $this->create($definition),
        };
    }

    public function has(string $id): bool
    {
        $resolved = $this->bindings->resolve($id);

        if ($this->definitions->has($resolved) || $this->singletons->has($resolved)) {
            return true;
        }

        // Autowire açıkken örneklenebilir her sınıf çözülebilir.
        return $this->autowire !== null && class_exists($resolved);
    }

    public function createScope(?string $name = null): ScopeInterface
    {
        $this->recorder?->recordScopeCreated();

        return $this->scopes->begin($name ?? 'scope', $this);
    }

    /**
     * Çözümleme sayaçları (DI-plan §28) — dev'de kayıt açıksa.
     */
    public function recorder(): ?ResolutionRecorder
    {
        return $this->recorder;
    }

    public function scope(): ?ScopeInterface
    {
        return $this->scopes->active();
    }

    public function isCompiled(): bool
    {
        return false;
    }

    public function hash(): ?string
    {
        return null;
    }

    public function scopeManager(): ScopeManager
    {
        return $this->scopes;
    }

    // ── Çözümleme ──────────────────────────────────────────────────

    /**
     * Tanımı bulur ve argümanlarını (gerekiyorsa lazy olarak) tamamlar.
     */
    private function definition(string $id): Definition
    {
        if (isset($this->completed[$id])) {
            return $this->completed[$id];
        }

        $explicit = $this->definitions->get($id);

        if ($explicit === null && $this->autowire === null) {
            throw NotFoundException::for($id, array_keys($this->resolving));
        }

        // Argüman doldurma da döngüye girebilir (A'nın constructor'ı B'yi
        // ister, B'nin de A'yı) — o yüzden koruma altında yapılır.
        $this->resolving[$id] = true;

        try {
            $definition = $this->autowire !== null
                ? $this->autowire->resolve($id, array_keys($this->resolving))
                : $explicit;
        } finally {
            unset($this->resolving[$id]);
        }

        if ($definition === null) {
            throw NotFoundException::for($id, array_keys($this->resolving));
        }

        return $this->completed[$id] = $definition;
    }

    private function create(Definition $definition): mixed
    {
        $this->resolving[$definition->id] = true;
        $started = $this->recorder === null ? 0 : hrtime(true);

        try {
            if ($definition->factory !== null) {
                return $this->invokeFactory($definition);
            }

            $arguments = [];
            foreach ($definition->arguments as $argument) {
                $arguments[] = $this->resolveArgument($argument, $definition);
            }

            $concrete = $definition->concrete;

            return new $concrete(...$arguments);
        } finally {
            unset($this->resolving[$definition->id]);

            // Süre, BAĞIMLILIKLARIN kurulumunu da içerir (iç içe create
            // çağrıları). Bu kasıtlı: "bu servisi kurmak ne kadar sürüyor"
            // sorusunun cevabı zincirin tamamıdır.
            $this->recorder?->recordInstantiation(
                $definition->id,
                $definition->lifetime,
                (hrtime(true) - $started) / 1e6,
            );
        }
    }

    /**
     * Tek bir argümanı çözer.
     *
     * Bu match, CompiledContainerGenerator'ın ürettiği kodla BİREBİR aynı
     * semantiği taşır. Buraya bir kol eklenirse generator'a da eklenmek
     * ZORUNDA — aksi halde dev/production davranışı ayrışır.
     */
    private function resolveArgument(ArgumentRef $argument, Definition $definition): mixed
    {
        return match ($argument->kind) {
            ArgumentKind::SERVICE => $this->get((string) $argument->id),

            ArgumentKind::LITERAL => $argument->value,

            ArgumentKind::EXTERNAL => $this->externals[(string) $argument->id]
                ?? throw ContainerException::missingExternal((string) $argument->id),

            ArgumentKind::CONTAINER => $this,

            // Etiketli liste (DI-plan §20). Üye listesi derleme zamanında
            // sabitlenmiştir; burada yalnızca çözülürler — etiket SORGUSU
            // yapılmaz.
            ArgumentKind::TAGGED => array_map(
                fn(string $id): mixed => $this->get($id),
                $argument->ids ?? []
            ),

            // Dekoratörün sardığı İÇ katman (DI-plan §21). Dahili id
            // (`Foo@inner.N`) normal bir servis gibi çözülür; onu özel kılan
            // yalnızca dekoratörün kendisine geri dönmemesini sağlamasıdır.
            ArgumentKind::DECORATED => $this->get((string) $argument->id),
        };
    }

    private function invokeFactory(Definition $definition): mixed
    {
        $factory = $definition->factory;

        if ($factory === null) {
            throw FactoryException::unsupported($definition->id, 'null');
        }

        return match ($factory->kind) {
            // Dev'de closure meşrudur; derlemede FactoryValidator reddeder ve
            // static metoda nasıl dönüştürüleceğini söyler.
            FactoryKind::CLOSURE => ($factory->closure)($this),

            FactoryKind::STATIC_METHOD => $this->callStatic(
                (string) $factory->class,
                (string) $factory->method
            ),

            // Factory'nin kendisi de bir servis: bağımlılıkları doğrulanır.
            FactoryKind::INVOKABLE => ($this->get((string) $factory->class))($this),

            // COMPILABLE dev'de de create()/__invoke ile çalışabilmeli; yoksa
            // yalnızca derlenmiş yolda kullanılabilir olurdu.
            FactoryKind::COMPILABLE => $this->invokeCompilable($definition, (string) $factory->class),
        };
    }

    /**
     * Static factory metodunu çağırır.
     *
     * Değişkende tutulan array callable kullanılır; derlenmiş container'da
     * bunun karşılığı düz `\Sınıf::metot($this)` çağrısıdır (indirection yok).
     */
    private function callStatic(string $class, string $method): mixed
    {
        $callable = [$class, $method];

        return $callable($this);
    }

    private function invokeCompilable(Definition $definition, string $class): mixed
    {
        if (method_exists($class, '__invoke')) {
            return ($this->get($class))($this);
        }

        if (method_exists($class, 'create')) {
            return $class::create($this);
        }

        throw new ContainerException(
            "[COMPILABLE_NO_RUNTIME] '{$class}' CompilableFactoryInterface uyguluyor ama\n"
            . "  dev'de çalıştırılamıyor: ne __invoke() ne static create() var.\n\n"
            . "  compile() yalnızca derlenmiş yolu kapsar; dev yolu için birini ekle.",
            'COMPILABLE_NO_RUNTIME',
            [$definition->id],
            $definition->id,
        );
    }

    /**
     * literal() ile verilen değeri döndürür.
     */
    private function literalValue(Definition $definition): mixed
    {
        $first = $definition->arguments[0] ?? null;

        if ($first !== null && $first->kind === ArgumentKind::LITERAL) {
            return $first->value;
        }

        throw ContainerException::missingExternal($definition->id);
    }
}
