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

final class Container implements ContainerInterface
{
    public const VERSION = '1.0.0';

    /** @var array<string, true> */
    private array $resolving = [];

    /**
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
        private readonly ?ResolutionRecorder $recorder = null,
    ) {}

    public function get(string $id): mixed
    {
        $resolved = $this->bindings->resolve($id);

        $this->recorder?->recordResolution($resolved);

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

        return $this->autowire !== null && class_exists($resolved);
    }

    public function createScope(?string $name = null): ScopeInterface
    {
        $this->recorder?->recordScopeCreated();

        return $this->scopes->begin($name ?? 'scope', $this);
    }

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

    private function definition(string $id): Definition
    {
        if (isset($this->completed[$id])) {
            return $this->completed[$id];
        }

        $explicit = $this->definitions->get($id);

        if ($explicit === null && $this->autowire === null) {
            throw NotFoundException::for($id, array_keys($this->resolving));
        }

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

            $this->recorder?->recordInstantiation(
                $definition->id,
                $definition->lifetime,
                (hrtime(true) - $started) / 1e6,
            );
        }
    }

    private function resolveArgument(ArgumentRef $argument, Definition $definition): mixed
    {
        return match ($argument->kind) {
            ArgumentKind::SERVICE => $this->get((string) $argument->id),

            ArgumentKind::LITERAL => $argument->value,

            ArgumentKind::EXTERNAL => $this->externals[(string) $argument->id]
                ?? throw ContainerException::missingExternal((string) $argument->id),

            ArgumentKind::CONTAINER => $this,

            ArgumentKind::TAGGED => array_map(
                fn(string $id): mixed => $this->get($id),
                $argument->ids ?? []
            ),

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
            FactoryKind::CLOSURE => ($factory->closure)($this),

            FactoryKind::STATIC_METHOD => $this->callStatic(
                (string) $factory->class,
                (string) $factory->method
            ),

            FactoryKind::INVOKABLE => ($this->get((string) $factory->class))($this),

            FactoryKind::COMPILABLE => $this->invokeCompilable($definition, (string) $factory->class),
        };
    }

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

    private function literalValue(Definition $definition): mixed
    {
        $first = $definition->arguments[0] ?? null;

        if ($first !== null && $first->kind === ArgumentKind::LITERAL) {
            return $first->value;
        }

        throw ContainerException::missingExternal($definition->id);
    }
}
