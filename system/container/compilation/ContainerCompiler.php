<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Binding\BindingRegistry;
use System\Container\Context\ContextualRegistry;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Decorator\DecoratorFlattener;
use System\Container\Decorator\DecoratorRegistry;
use System\Container\Tag\TagRegistry;
use System\Container\Definition\Definition;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Resolution\AutowireResolver;
use System\Container\Resolution\ParameterResolver;
use System\Container\Resolution\ReflectionCache;
use Throwable;

final class ContainerCompiler
{
    private readonly ReflectionCache $reflection;
    private readonly AutowireResolver $resolver;
    private readonly RootCollector $roots;

    public function __construct(
        private readonly string $appRoot,
        private readonly DefinitionRegistry $definitions,
        private readonly BindingRegistry $bindings,
        private readonly array $cutEdges = [],
        private readonly bool $useContentHash = false,
        ?RootCollector $roots = null,
        private readonly ?ContextualRegistry $contextual = null,
        private readonly ?TagRegistry $tags = null,
        private readonly ?DecoratorRegistry $decorators = null,
    ) {
        $this->reflection = new ReflectionCache();
        $this->resolver = new AutowireResolver(
            definitions: $this->definitions,
            bindings: $this->bindings,
            parameters: new ParameterResolver(
                $this->bindings,
                $this->reflection,
                $this->definitions,
                $this->contextual,
                $this->tags,
            ),
            reflection: $this->reflection,
        );
        $this->roots = $roots ?? new RootCollector($this->appRoot);
    }

    /**
     * @param bool $discoverRoots
     */
    public static function fromBuilder(
        string $appRoot,
        ContainerBuilderInterface $builder,
        bool $useContentHash = false,
        bool $discoverRoots = true,
    ): self {
        DecoratorFlattener::apply($builder->definitions(), $builder->decorators(), $builder->bindings());

        return new self(
            appRoot: $appRoot,
            definitions: $builder->definitions(),
            bindings: $builder->bindings(),
            cutEdges: $builder->cutEdges(),
            useContentHash: $useContentHash,
            roots: new RootCollector($appRoot, discover: $discoverRoots),
            contextual: $builder->contextual(),
            tags: $builder->tags(),
            decorators: $builder->decorators(),
        );
    }

    /**
     * @param list<string>|null $extraRoots
     */
    public function compile(?array $extraRoots = null, bool $generate = true): CompilationResult
    {
        $stats = [];
        $started = hrtime(true);

        $rootMap = $this->roots->collect(
            $this->definitions,
            $this->bindings,
            [...($extraRoots ?? []), ...$this->phaseFiveRoots()],
        );
        $stats['roots'] = count($rootMap);
        $stats['ms_roots'] = $this->since($started);

        $mark = hrtime(true);
        [$resolved, $diagnostics, $skipped, $errored] = $this->closure(array_keys($rootMap));
        $stats['services'] = count($resolved);
        $stats['ms_closure'] = $this->since($mark);

        $mark = hrtime(true);
        $graph = DependencyGraph::fromDefinitions($resolved, $this->bindings);
        $missing = $graph->missing();
        $stats['edges'] = $graph->edgeCount();
        $stats['ms_graph'] = $this->since($mark);

        foreach ($missing as $id => $dependents) {
            if (isset($errored[$id])) {
                continue;
            }

            $diagnostics[] = Diagnostic::error(
                'MISSING_DEPENDENCY',
                "Bağımlılık çözülemedi: '{$id}'",
                [...array_slice($dependents, 0, 4), $id],
                'tanım yok',
                "Bu servisi isteyen " . count($dependents) . " tanım var ama kendisi\n"
                . "derleme kapsamına girmemiş. Muhtemel sebepler:\n"
                . "  • autowire edilemedi (yukarıdaki diğer hatalara bak)\n"
                . "  • framework dışı bir sınıf (PDO, Redis, Twig) — açıkça\n"
                . "    bind edilmeli veya factory ile kurulmalı\n"
                . "Çözüm: bir provider'da kaydet, ya da bağımlılığı kaldır.",
                $id,
            );
        }

        $mark = hrtime(true);

        $cycleDiagnostics = (new CircularDependencyValidator())->validate($graph, $this->cutEdges);
        $diagnostics = [...$diagnostics, ...$cycleDiagnostics];

        $hasCycle = false;
        foreach ($cycleDiagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                $hasCycle = true;
                break;
            }
        }

        if (!$hasCycle) {
            $diagnostics = [...$diagnostics, ...(new ScopeValidator())->validate($graph)];
        } else {
            $diagnostics[] = Diagnostic::notice(
                'SCOPE_CHECK_SKIPPED',
                'Scope doğrulaması atlandı (önce döngüleri düzeltin).',
                [],
                null,
                'Döngü varken scope analizi anlamlı sonuç vermez.',
            );
        }

        $diagnostics = [...$diagnostics, ...(new FactoryValidator())->validate($resolved)];
        $diagnostics = [...$diagnostics, ...$this->validateExportability($resolved)];

        $stats['ms_validate'] = $this->since($mark);

        $mark = hrtime(true);
        $registry = new DefinitionRegistry();
        foreach ($resolved as $definition) {
            $registry->set($definition);
        }

        $hash = (new CacheHash(
            appRoot: $this->appRoot,
            configFiles: $this->configFiles(),
            useContentHash: $this->useContentHash,
        ))->compute(
            $registry,
            $this->bindings,

            $this->phaseFiveFingerprint(),
            $this->reflection,
        );
        $stats['ms_hash'] = $this->since($mark);

        $stats['ms_total'] = $this->since($started);

        $result = new CompilationResult(
            definitions: $resolved,
            graph: $graph,
            diagnostics: $diagnostics,
            hash: $hash,
            roots: $rootMap,
            skipped: $skipped,
            missing: $missing,
            stats: $stats,
        );

        if (!$generate || !$result->isSuccessful()) {
            return $result;
        }

        $mark = hrtime(true);
        $source = (new CompiledContainerGenerator())->generate(
            $resolved,
            $hash,
            ['php' => PHP_VERSION, 'root' => $this->appRoot],
            $this->bindings,
        );
        $stats['ms_codegen'] = $this->since($mark);
        $stats['bytes'] = strlen($source);
        $stats['ms_total'] = $this->since($started);

        return (new CompilationResult(
            definitions: $resolved,
            graph: $graph,
            diagnostics: $diagnostics,
            hash: $hash,
            roots: $rootMap,
            skipped: $skipped,
            missing: $missing,
            stats: $stats,
        ))->withGenerated($source, CompiledContainerGenerator::className($hash));
    }

    public function currentHash(?array $extraRoots = null): string
    {
        return $this->compile($extraRoots, generate: false)->hash;
    }

    /**
     * @param list<string> $roots
     * @return array{ 0: array<string, Definition>, 1: list<Diagnostic>, 2: array<string, string>, 3: array<string, true> }
     */
    private function closure(array $roots): array
    {
        /** @var array<string, Definition> */
        $resolved = [];
        /** @var list<Diagnostic> */
        $diagnostics = [];
        /** @var array<string, string> */
        $skipped = [];
        /** @var array<string, true> */
        $errored = [];
        /** @var array<string, true> */
        $seen = [];
        /** @var array<string, list<string>> */
        $chains = [];

        $queue = $roots;

        while ($queue !== []) {
            $id = array_shift($queue);

            $target = $this->bindings->resolve($id);

            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;

            $chain = $chains[$target] ?? [];

            try {
                $definition = $this->resolver->resolve($target, $chain);
            } catch (Throwable $e) {
                $diagnostics[] = Diagnostic::fromThrowable(
                    $e,
                    $target,
                    $this->definitions->get($target)?->source,
                );
                $skipped[$target] = 'çözümleme hatası: ' . $this->firstLine($e->getMessage());
                $errored[$target] = true;

                continue;
            }

            $resolved[$target] = $definition;

            foreach ($definition->edges() as $dependency) {
                $dependencyTarget = $this->bindings->resolve($dependency);

                if (isset($seen[$dependencyTarget])) {
                    continue;
                }

                if (!$this->definitions->has($dependencyTarget)
                    && !$this->roots->isAllowed($dependencyTarget)
                ) {
                    $skipped[$dependencyTarget] = 'framework dışı namespace — açık binding gerekli';

                    continue;
                }

                $chains[$dependencyTarget] ??= [...$chain, $target];
                $queue[] = $dependencyTarget;
            }
        }

        ksort($resolved);
        ksort($skipped);

        return [$resolved, $diagnostics, $skipped, $errored];
    }

    /**
     * @param array<string, Definition> $definitions
     * @return list<Diagnostic>
     */
    private function validateExportability(array $definitions): array
    {
        $diagnostics = [];
        $writer = new CodeWriter();

        foreach ($definitions as $id => $definition) {
            foreach ($definition->arguments as $argument) {
                if ($argument->kind !== \System\Container\Definition\ArgumentKind::LITERAL) {
                    continue;
                }

                try {
                    $writer->export($argument->value, $id . '::$' . $argument->parameter);
                } catch (Throwable $e) {
                    $diagnostics[] = Diagnostic::fromThrowable($e, $id, $definition->source);
                }
            }
        }

        return $diagnostics;
    }

    /**
     * @return array<string, mixed>
     */
    private function phaseFiveFingerprint(): array
    {
        $contextual = [];
        foreach ($this->contextual?->all() ?? [] as $binding) {
            $contextual[] = [
                'consumer' => $binding->consumer,
                'needs' => $binding->needs,
                'giveId' => $binding->giveId,
                'isValue' => $binding->isValue,

                'giveValue' => $binding->isValue ? $binding->giveValue : null,
            ];
        }

        $decorators = [];
        foreach ($this->decorators?->all() ?? [] as $id => $chain) {
            $decorators[$id] = array_map(
                static fn(array $layer): string => $layer['class'],
                $chain,
            );
        }

        return [
            'tags' => $this->tags?->all() ?? [],
            'contextual' => $contextual,
            'decorators' => $decorators,
        ];
    }

    /**
     * @return list<string>
     */
    private function phaseFiveRoots(): array
    {
        $roots = [];

        foreach ($this->tags?->all() ?? [] as $ids) {
            foreach ($ids as $id) {
                $roots[] = $id;
            }
        }

        foreach ($this->decorators?->all() ?? [] as $id => $chain) {
            foreach (array_keys($chain) as $level) {
                $roots[] = DecoratorRegistry::innerId($id, $level);
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return array<string, string>
     */
    private function configFiles(): array
    {
        return [
            'providers' => $this->appRoot . '/config/providers.php',
            'config' => $this->appRoot . '/config/config.php',
            'middleware' => $this->appRoot . '/config/middleware.php',
        ];
    }

    private function since(int $from): float
    {
        return round((hrtime(true) - $from) / 1e6, 2);
    }

    private function firstLine(string $message): string
    {
        $first = strtok($message, "\n");

        return $first === false ? $message : $first;
    }

    public function reflectionCache(): ReflectionCache
    {
        return $this->reflection;
    }
}
