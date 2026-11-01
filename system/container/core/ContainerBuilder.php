<?php

declare(strict_types=1);

namespace System\Container\Core;

use System\Container\Binding\Binding;
use System\Container\Binding\BindingRegistry;
use System\Container\Context\ContextualBinding;
use System\Container\Context\ContextualRegistry;
use System\Container\Decorator\DecoratorFlattener;
use System\Container\Decorator\DecoratorRegistry;
use System\Container\Tag\TagRegistry;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Contract\ContainerInterface;
use System\Container\Contract\ServiceProviderInterface;
use System\Container\Definition\Definition;
use System\Container\Definition\ArgumentRef;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Definition\ExportGuard;
use System\Container\Definition\FactoryRef;
use System\Container\Definition\LazyRef;
use System\Container\Exception\BindingException;
use System\Container\Exception\ContainerException;
use System\Container\Exception\InvalidDefinitionException;
use System\Container\Lifetime\Lifetime;
use System\Container\Lifetime\ScopeManager;
use System\Container\Lifetime\SingletonStore;
use System\Container\Observability\ResolutionRecorder;
use System\Container\Resolution\AutowireResolver;
use System\Container\Resolution\ParameterResolver;
use System\Container\Resolution\ReflectionCache;
use System\Runtime\Sapi;

final class ContainerBuilder implements ContainerBuilderInterface
{
    private DefinitionRegistry $definitions;
    private BindingRegistry $bindings;
    private ContextualRegistry $contextual;
    private TagRegistry $tags;
    private DecoratorRegistry $decorators;

    /** @var array<class-string, object> */
    private array $externals = [];

    /** @var array<string, array{from: string, to: string, reason: string}> */
    private array $cutEdges = [];

    /**
     * @var list<array{id: string, previous: ?string, current: ?string}>
     */
    private array $overridden = [];

    private bool $locked = false;
    private bool $autowire = true;
    private ?ResolutionRecorder $recorder = null;

    public function __construct()
    {
        $this->definitions = new DefinitionRegistry();
        $this->bindings = new BindingRegistry();
        $this->contextual = new ContextualRegistry();
        $this->tags = new TagRegistry();
        $this->decorators = new DecoratorRegistry();
    }

    /**
     * @param array<class-string, object> $instances
     * @param Sapi|null $sapi
     * @param list<class-string<ServiceProviderInterface>>|null $providers
     */
    public static function fromProviders(
        string $appRoot,
        ?Sapi $sapi = null,
        array $instances = [],
        ?array $providers = null,
    ): self {
        $builder = new self();

        foreach ($instances as $id => $value) {
            $builder->instance($id, $value);
        }

        foreach (self::instantiateProviders($appRoot, $sapi, $providers) as $provider) {
            $provider->register($builder);
        }

        return $builder;
    }

    /**
     * @param Sapi|null $sapi
     * @param list<class-string<ServiceProviderInterface>>|null $providers
     * @return list<ServiceProviderInterface>
     */
    public static function instantiateProviders(
        string $appRoot,
        ?Sapi $sapi = null,
        ?array $providers = null,
    ): array {
        $classes = $providers ?? self::providerList($appRoot);

        $instances = [];
        /** @var array<string, string> */
        $declared = [];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new ContainerException(
                    "[PROVIDER_NOT_FOUND] Provider sınıfı bulunamadı: '{$class}'\n\n"
                    . "  config/providers.php içinde listeli ama yüklenemedi.\n"
                    . "  Autoloader namespace'i küçük harfli dizin yoluna çevirir —\n"
                    . "  dizin adlarının küçük harf olduğunu doğrula.",
                    'PROVIDER_NOT_FOUND',
                    [$class],
                    $class,
                );
            }

            $provider = new $class();

            if (!$provider instanceof ServiceProviderInterface) {
                throw new ContainerException(
                    "[INVALID_PROVIDER] '{$class}' ServiceProviderInterface uygulamıyor.",
                    'INVALID_PROVIDER',
                    [$class],
                    $class,
                );
            }

            if ($sapi !== null && !$provider->supports($sapi)) {
                continue;
            }

            foreach ($provider->provides() as $id) {
                if (isset($declared[$id])) {
                    throw BindingException::duplicateProvider($id, $declared[$id], $class);
                }
                $declared[$id] = $class;
            }

            $instances[] = $provider;
        }

        return $instances;
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    public static function providerList(string $appRoot): array
    {
        $file = rtrim($appRoot, '/\\') . '/config/providers.php';

        if (!is_file($file)) {
            throw new ContainerException(
                "[PROVIDERS_MISSING] Provider listesi yok: {$file}\n\n"
                . "  Servis tanımlarının tek kaynağı bu dosyadır; web bootstrap'ı ve\n"
                . "  `container:compile` onu paylaşır.",
                'PROVIDERS_MISSING',
            );
        }

        /** @var list<class-string<ServiceProviderInterface>> $list */
        $list = require $file;

        return $list;
    }

    public function bind(string $id, ?string $concrete = null, Lifetime $lifetime = Lifetime::TRANSIENT): static
    {
        $this->assertMutable($id, 'bind');

        $concrete ??= $id;

        if ($concrete !== $id) {
            $this->bindings->set(new Binding($id, $concrete, $this->callSite()));
        }

        $this->define(new Definition(
            id: $concrete,
            concrete: $concrete,
            lifetime: $lifetime,
            source: $this->callSite(),
        ));

        return $this;
    }

    public function singleton(string $id, ?string $concrete = null): static
    {
        return $this->bind($id, $concrete, Lifetime::SINGLETON);
    }

    public function scoped(string $id, ?string $concrete = null): static
    {
        return $this->bind($id, $concrete, Lifetime::SCOPED);
    }

    public function transient(string $id, ?string $concrete = null): static
    {
        return $this->bind($id, $concrete, Lifetime::TRANSIENT);
    }

    public function factory(
        string $id,
        callable|array|string $factory,
        Lifetime $lifetime = Lifetime::SINGLETON,
        array $dependsOn = [],
    ): static {
        $this->assertMutable($id, 'factory');

        $this->define(new Definition(
            id: $id,
            concrete: $id,
            lifetime: $lifetime,
            factory: FactoryRef::from($factory, $id),
            dependsOn: array_values($dependsOn),
            source: $this->callSite(),
        ));

        return $this;
    }

    public function instance(string $id, object $value): static
    {
        $this->assertMutable($id, 'instance');

        $this->externals[$id] = $value;

        $this->define(new Definition(
            id: $id,
            concrete: $value::class,
            lifetime: Lifetime::INSTANCE,
            source: $this->callSite(),
        ));

        return $this;
    }

    public function literal(string $id, mixed $value): static
    {
        $this->assertMutable($id, 'literal');

        $reason = ExportGuard::reject($value);
        if ($reason !== null) {
            throw InvalidDefinitionException::notExportable($id, 'literal(' . $id . ')', $reason);
        }

        $this->define(new Definition(
            id: $id,
            concrete: get_debug_type($value),
            lifetime: Lifetime::INSTANCE,
            arguments: [ArgumentRef::literal($value, 'value')],
            source: $this->callSite(),
        ));

        return $this;
    }

    public function alias(string $id, string $target): static
    {
        $this->assertMutable($id, 'alias');

        $this->bindings->set(new Binding($id, $target, $this->callSite()));

        return $this;
    }

    public function lazyRef(string $id): LazyRef
    {
        return new LazyRef($id, function () use ($id): mixed {
            $container = $this->built ?? throw new ContainerException(
                "[LAZYREF_BEFORE_BUILD] LazyRef('{$id}') container kurulmadan çözüldü.\n\n"
                . "  LazyRef yalnızca runtime'da, container hazırken çözülebilir.\n"
                . "  register() içinde çözmeye çalışıyorsan: register() saf olmak zorunda.",
                'LAZYREF_BEFORE_BUILD',
                [$id],
                $id,
            );

            return $container->get($id);
        });
    }

    public function cutEdge(string $from, string $to, string $reason): static
    {
        $this->assertMutable($from, 'cutEdge');

        $this->cutEdges[$from . '=>' . $to] = [
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ];

        return $this;
    }

    public function when(string $consumer): ContextualBindingBuilder
    {
        $this->assertMutable($consumer, 'when');

        return new ContextualBindingBuilder($this, $consumer, $this->callSite());
    }

    public function addContextualBinding(ContextualBinding $binding): static
    {
        $this->assertMutable($binding->consumer, 'contextual');

        $this->contextual->add($binding);

        return $this;
    }

    public function tag(array $ids, string $tag): static
    {
        $this->assertMutable($tag, 'tag');

        $this->tags->add($tag, array_values($ids));

        return $this;
    }

    public function decorate(string $id, string $decorator): static
    {
        $this->assertMutable($id, 'decorate');

        $this->decorators->add($id, $decorator, $this->callSite());

        return $this;
    }

    public function contextual(): ContextualRegistry
    {
        return $this->contextual;
    }

    public function tags(): TagRegistry
    {
        return $this->tags;
    }

    public function decorators(): DecoratorRegistry
    {
        return $this->decorators;
    }

    public function autowire(bool $enabled = true): static
    {
        $this->autowire = $enabled;

        return $this;
    }

    public function observe(bool $enabled = true): static
    {
        $this->recorder = $enabled ? new ResolutionRecorder() : null;

        return $this;
    }

    public function recorder(): ?ResolutionRecorder
    {
        return $this->recorder;
    }

    public function lock(): static
    {
        $this->locked = true;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function has(string $id): bool
    {
        return $this->definitions->has($id) || $this->bindings->has($id);
    }

    public function definitions(): DefinitionRegistry
    {
        return $this->definitions;
    }

    public function bindings(): BindingRegistry
    {
        return $this->bindings;
    }

    public function externals(): array
    {
        return $this->externals;
    }

    public function cutEdges(): array
    {
        return $this->cutEdges;
    }

    /**
     * @return list<array{id: string, previous: ?string, current: ?string}>
     */
    public function overriddenDefinitions(): array
    {
        return $this->overridden;
    }

    public function isAutowireEnabled(): bool
    {
        return $this->autowire;
    }

    private ?ContainerInterface $built = null;

    public function build(): ContainerInterface
    {
        if ($this->built !== null) {
            return $this->built;
        }

        DecoratorFlattener::apply($this->definitions, $this->decorators, $this->bindings);

        $this->lock();

        $reflectionCache = new ReflectionCache();

        $resolver = $this->autowire
            ? new AutowireResolver(
                definitions: $this->definitions,
                bindings: $this->bindings,

                parameters: new ParameterResolver(
                    $this->bindings,
                    $reflectionCache,
                    $this->definitions,
                    $this->contextual,
                    $this->tags,
                ),
                reflection: $reflectionCache,
            )
            : null;

        return $this->built = new Container(
            definitions: $this->definitions,
            bindings: $this->bindings,
            autowire: $resolver,
            singletons: new SingletonStore(),
            scopes: new ScopeManager(),
            externals: $this->externals,
            recorder: $this->recorder,
        );
    }

    private function define(Definition $definition): void
    {
        $existing = $this->definitions->get($definition->id);

        if ($existing !== null) {
            $this->overridden[] = [
                'id' => $definition->id,
                'previous' => $existing->source,
                'current' => $definition->source,
            ];
        }

        $this->definitions->set($definition);
    }

    private function assertMutable(string $id, string $operation): void
    {
        if ($this->locked) {
            throw ContainerException::locked($id, $operation);
        }
    }

    private function callSite(): ?string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null) {
                continue;
            }

            if (str_ends_with(str_replace('\\', '/', $file), 'system/container/core/ContainerBuilder.php')) {
                continue;
            }

            $relative = $file;
            if (defined('APP_ROOT')) {
                $root = str_replace('\\', '/', APP_ROOT);
                $normalized = str_replace('\\', '/', $file);
                if (str_starts_with($normalized, $root)) {
                    $relative = ltrim(substr($normalized, strlen($root)), '/');
                }
            }

            return $relative . ':' . ($frame['line'] ?? 0);
        }

        return null;
    }
}
