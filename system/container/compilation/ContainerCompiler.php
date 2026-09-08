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

/**
 * Derleme boru hattı (DI-plan §15).
 *
 * ALTI GEÇİŞ:
 *   1. Roots      — kök servis kümesi (RootCollector)
 *   2. Closure    — köklerin transitive closure'ı, reflection ile
 *   3. Graph      — bağımlılık grafiği (deterministik sıra)
 *   4. Validate   — döngü → scope → factory (bu sırayla)
 *   5. Codegen    — PHP kaynağı üretimi
 *   6. Write      — atomik yazma (çağıran yapar; compile() yazmaz)
 *
 * DOĞRULAMA SIRASI ÖNEMLİ: döngü varsa scope analizi anlamsızdır (sonsuz yol
 * üretir), o yüzden döngü hatası varsa scope geçişi atlanır.
 *
 * İSTİSNA DEĞİL DIAGNOSTIC: compiler bulgu TOPLAR, fırlatmaz. 12 problemi
 * olan bir grafiği düzeltmek 12 derleme koşusu almasın diye.
 */
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
        /**
         * Faz 5 registry'leri (DI-plan §12, §20, §21). Verilmezse boş
         * kabul edilir — Faz 1-4 davranışı.
         */
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
     * @param bool $discoverRoots false ise controller/komut/middleware
     *        taraması yapılmaz; kök kümesi yalnızca açık tanımlardan oluşur.
     */
    public static function fromBuilder(
        string $appRoot,
        ContainerBuilderInterface $builder,
        bool $useContentHash = false,
        bool $discoverRoots = true,
    ): self {
        // Dekoratör zincirleri tanımlara UYGULANIR (iç katmanlar dahili
        // id'lere taşınır) — derleyici düzleştirilmiş tanımları görmek
        // zorunda, aksi halde dekoratörler derlenmiş çıktıda hiç yer almaz.
        //
        // İdempotenttir: `build()` de aynı işlemi yapar ve iki kez uygulamak
        // aynı sonucu verir (dahili id'ler zaten yerinde).
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

        // ── 1. Roots ───────────────────────────────────────────────
        //
        // Etiket üyeleri ve dekoratör katmanları da kök: etiketli bir servis
        // yalnızca `#[Tagged]` üzerinden istenebilir ve o parametre başka bir
        // servisin içinde olabilir; dekoratör katmanları ise yalnızca dahili
        // id ile bilinir. Kök kümesine alınmazlarsa closure onlara ulaşmaz ve
        // derlenmiş container'da bulunmazlar.
        $rootMap = $this->roots->collect(
            $this->definitions,
            $this->bindings,
            [...($extraRoots ?? []), ...$this->phaseFiveRoots()],
        );
        $stats['roots'] = count($rootMap);
        $stats['ms_roots'] = $this->since($started);

        // ── 2. Closure ─────────────────────────────────────────────
        $mark = hrtime(true);
        [$resolved, $diagnostics, $skipped, $errored] = $this->closure(array_keys($rootMap));
        $stats['services'] = count($resolved);
        $stats['ms_closure'] = $this->since($mark);

        // ── 3. Graph ───────────────────────────────────────────────
        $mark = hrtime(true);
        $graph = DependencyGraph::fromDefinitions($resolved, $this->bindings);
        $missing = $graph->missing();
        $stats['edges'] = $graph->edgeCount();
        $stats['ms_graph'] = $this->since($mark);

        foreach ($missing as $id => $dependents) {
            // Bu servis closure geçişinde ZATEN HATA VERDİYSE (örn. bind
            // edilmemiş interface) aynı kök nedeni ikinci kez raporlamak
            // çıktıyı boğar ve gerçek hata sayısını şişirir.
            //
            // DİKKAT: koşul `$errored` üzerinde, `$skipped` üzerinde DEĞİL.
            // "Framework dışı namespace" gibi sebeplerle atlanan id'ler hiç
            // hata basmaz; onları da bastırmak, var olmayan bir metoda çağrı
            // üreten (require anında fatal veren) container'ın sessizce
            // derlenmesine yol açar.
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

        // ── 4. Validate ────────────────────────────────────────────
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
            // Döngü varken scope analizi anlamsız: geçişli kural sonsuz yol üretir.
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

        // ── Hash ───────────────────────────────────────────────────
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
            // Faz 5 kayıtları hash'e GİRMEK ZORUNDA (DI-plan §31): bir
            // etikete servis eklemek veya bir dekoratör katmanı takmak
            // üretilen kodu değiştirir. Girmezlerse `--check` kapısı bu
            // değişiklikleri "güncel" sayar ve production bayat bir zincirle
            // çalışır.
            $this->phaseFiveFingerprint(),
            $this->reflection,
        );
        $stats['ms_hash'] = $this->since($mark);

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

        // ── 5. Codegen ─────────────────────────────────────────────
        if (!$generate || !$result->isSuccessful()) {
            $stats['ms_total'] = $this->since($started);

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

    /**
     * Yalnızca mevcut tanımların hash'i — bayatlık kontrolü için (§31, §32).
     *
     * Tam derleme yapmadan "canlı derleme güncel mi?" sorusuna cevap verir.
     */
    public function currentHash(?array $extraRoots = null): string
    {
        return $this->compile($extraRoots, generate: false)->hash;
    }

    // ── Geçiş 2: transitive closure ────────────────────────────────

    /**
     * Köklerden başlayarak erişilebilir tüm servisleri çözer.
     *
     * Hata toplanır ve o node ATLANIR; alt ağacı da atlanır (aynı hatayı 20
     * kez raporlamamak için). Böylece tek koşuda tüm bağımsız problemler
     * görülür ama tek bir kök hata çıktıyı boğmaz.
     *
     * @param list<string> $roots
     * @return array{
     *     0: array<string, Definition>,
     *     1: list<Diagnostic>,
     *     2: array<string, string>,
     *     3: array<string, true>
     * } [tanımlar, bulgular, atlananlar+sebep, hata basılmış id'ler]
     */
    private function closure(array $roots): array
    {
        /** @var array<string, Definition> */
        $resolved = [];
        /** @var list<Diagnostic> */
        $diagnostics = [];
        /** @var array<string, string> */
        $skipped = [];
        /** @var array<string, true> Hakkında zaten hata basılmış id'ler */
        $errored = [];
        /** @var array<string, true> */
        $seen = [];
        /** @var array<string, list<string>> id => onu isteyen zincir */
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

                // Framework dışı sınıflar örtük autowire edilmez: açıkça
                // bind edilmiş değilse kuyruğa alınmaz ve MISSING_DEPENDENCY
                // olarak raporlanır — sessizce reflection'a düşmek yerine.
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
     * Literal argümanların ve INSTANCE literal'lerinin gerçekten
     * gömülebilir olduğunu doğrular.
     *
     * ParameterResolver bunu zaten kontrol eder; bu geçiş, açık `literal()`
     * çağrıları ve ileride eklenecek argüman türleri için ikinci savunma
     * hattı. Atlanırsa require edildiğinde fatal veren kod üretilir.
     *
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
     * Faz 5 kayıtlarının hash parmak izi (DI-plan §31).
     *
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
                // Değer hash'e girer: `giveValue(30)` → `giveValue(60)`
                // üretilen kodu değiştirir.
                'giveValue' => $binding->isValue ? $binding->giveValue : null,
            ];
        }

        $decorators = [];
        foreach ($this->decorators?->all() ?? [] as $id => $chain) {
            // SIRA korunur: dekoratör sırası zincirin anlamını belirler.
            $decorators[$id] = array_map(
                static fn(array $layer): string => $layer['class'],
                $chain,
            );
        }

        return [
            // Etiket üye SIRASI da hash'e girer: pipeline zincirlerinde
            // sıra anlamsaldır.
            'tags' => $this->tags?->all() ?? [],
            'contextual' => $contextual,
            'decorators' => $decorators,
        ];
    }

    /**
     * Faz 5 kaynaklı ek kökler: etiket üyeleri ve dekoratör katmanları.
     *
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
            // Dış id zaten tanım olarak kök; dahili katmanlar da eklenir.
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
