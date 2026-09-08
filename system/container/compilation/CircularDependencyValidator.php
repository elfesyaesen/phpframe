<?php

declare(strict_types=1);

namespace System\Container\Compilation;

/**
 * Döngüsel bağımlılık tespiti (DI-plan §18).
 *
 * ALGORİTMA — iteratif üç renkli DFS, explicit path yığını ile.
 *
 * Neden özyinelemeli değil: 150+ node'lu bir grafikte derin bir zincir PHP
 * stack'ini tüketebilir, ve daha önemlisi özyinelemeden döngü ZİNCİRİNİ
 * çıkarmak zordur — oysa zincirsiz bir döngü hatası işe yaramaz.
 *
 * Neden TÜM döngüler raporlanır (ilk değil): karışık bir bootstrap'ta 3 döngü
 * varsa, tek tek bulup 3 kez derlemek yerine hepsi bir koşuda görülür.
 * Kanonik rotasyon (en küçük id başa) ile aynı döngü iki kez raporlanmaz.
 *
 * BİLDİRİLMİŞ KESİK KENARLAR: `cutEdge(from, to, reason)` ile bildirilmiş bir
 * kenardan geçen döngü hata değil BİLGİ üretir. Bildirim yoksa hata verir.
 * Böylece "bu döngüyü biliyorum ve şu closure ile kırıyorum" bilgisi yorumda
 * değil, doğrulanabilir veri olarak durur.
 */
final class CircularDependencyValidator
{
    private const WHITE = 0; // ziyaret edilmedi
    private const GRAY = 1;  // yığında (bu yolda)
    private const BLACK = 2; // bitti

    /**
     * @param array<string, array{from: string, to: string, reason: string}> $cutEdges
     * @return list<Diagnostic>
     */
    public function validate(DependencyGraph $graph, array $cutEdges = []): array
    {
        $cut = [];
        foreach ($cutEdges as $edge) {
            $cut[$edge['from'] . '=>' . $edge['to']] = $edge['reason'];
        }

        /** @var array<string, int> */
        $color = [];
        /** @var array<string, list<string>> kanonik döngü => zincir */
        $cycles = [];
        /** @var array<string, string> bildirilmiş kenarla kırılan döngüler */
        $declared = [];
        /** @var array<string, true> bildirilmiş ama artık döngü olmayan kenarlar */
        $usedCuts = [];

        foreach ($graph->ids() as $root) {
            if (($color[$root] ?? self::WHITE) !== self::WHITE) {
                continue;
            }

            $this->dfs($graph, $root, $color, $cycles, $declared, $usedCuts, $cut);
        }

        $diagnostics = [];

        foreach ($cycles as $canonical => $chain) {
            $diagnostics[] = $this->cycleError($graph, $chain);
        }

        foreach ($declared as $canonical => $info) {
            $diagnostics[] = Diagnostic::notice(
                'CYCLE_CUT_DECLARED',
                'Döngü bildirilmiş lazy kenar ile kırılmış.',
                $this->describeChain($graph, explode(' → ', $canonical)),
                'kesik kenar (bildirilmiş)',
                "Sebep: {$info}\n"
                . "Bu döngü kasıtlı olarak kesilmiş; hata verilmiyor.\n"
                . "Kenar `cutEdge()` bildirimi kaldırılırsa hata haline gelir."
            );
        }

        // Bildirilmiş ama artık döngü üretmeyen kenarlar: ölü bildirim.
        foreach ($cut as $key => $reason) {
            if (!isset($usedCuts[$key])) {
                [$from, $to] = explode('=>', $key, 2);
                $diagnostics[] = Diagnostic::notice(
                    'CYCLE_CUT_OBSOLETE',
                    'Bildirilmiş döngü-kesme kenarı artık gereksiz.',
                    [$from . ' ⇢ ' . $to],
                    'döngü yok',
                    "Sebep olarak '{$reason}' bildirilmiş ama bu kenar artık bir\n"
                    . "döngünün parçası değil (bağımlılıklar değişmiş olabilir).\n"
                    . "Çözüm: cutEdge() bildirimini kaldır ve LazyRef'i doğrudan\n"
                    . "enjeksiyona çevirip çevirebileceğini kontrol et."
                );
            }
        }

        return $diagnostics;
    }

    /**
     * @param array<string, int>                  $color
     * @param array<string, list<string>>         $cycles
     * @param array<string, string>               $declared
     * @param array<string, true>                 $usedCuts
     * @param array<string, string>               $cut
     */
    private function dfs(
        DependencyGraph $graph,
        string $root,
        array &$color,
        array &$cycles,
        array &$declared,
        array &$usedCuts,
        array $cut,
    ): void {
        $color[$root] = self::GRAY;

        /** @var list<string> */
        $path = [$root];
        /** @var list<array{0: string, 1: list<string>, 2: int}> [node, kenarlar, sonraki index] */
        $stack = [[$root, $graph->knownEdges($root), 0]];

        while ($stack !== []) {
            $top = count($stack) - 1;
            [$current, $edges, $index] = $stack[$top];

            if ($index >= count($edges)) {
                $color[$current] = self::BLACK;
                array_pop($stack);
                array_pop($path);
                continue;
            }

            $stack[$top][2] = $index + 1;
            $target = $edges[$index];

            $edgeKey = $current . '=>' . $target;

            if (($color[$target] ?? self::WHITE) === self::GRAY) {
                // Döngü bulundu: path'te $target'ın konumundan sonu al.
                $start = array_search($target, $path, true);
                $cycle = $start === false ? [...$path, $target] : [...array_slice($path, $start), $target];

                $canonical = $this->canonical($cycle);

                if (isset($cut[$edgeKey])) {
                    $usedCuts[$edgeKey] = true;
                    $declared[$canonical] ??= $cut[$edgeKey];
                } else {
                    $cycles[$canonical] ??= $cycle;
                }

                continue;
            }

            if (($color[$target] ?? self::WHITE) === self::WHITE) {
                $color[$target] = self::GRAY;
                $path[] = $target;
                $stack[] = [$target, $graph->knownEdges($target), 0];
            }
        }
    }

    /**
     * Döngünün rotasyondan bağımsız kimliği: en küçük id başa alınır.
     * Aynı döngüyü farklı başlangıç noktalarından iki kez raporlamayı önler.
     *
     * @param list<string> $cycle Son eleman ilk elemanla aynıdır
     */
    private function canonical(array $cycle): string
    {
        $ring = array_slice($cycle, 0, -1);

        if ($ring === []) {
            return implode(' → ', $cycle);
        }

        $minIndex = 0;
        foreach ($ring as $i => $id) {
            if (strcmp($id, $ring[$minIndex]) < 0) {
                $minIndex = $i;
            }
        }

        $rotated = [...array_slice($ring, $minIndex), ...array_slice($ring, 0, $minIndex)];
        $rotated[] = $rotated[0];

        return implode(' → ', $rotated);
    }

    /**
     * @param list<string> $chain
     */
    private function cycleError(DependencyGraph $graph, array $chain): Diagnostic
    {
        return Diagnostic::error(
            'CIRCULAR_DEPENDENCY',
            'Döngüsel bağımlılık tespit edildi.',
            $this->describeChain($graph, $chain),
            'döngü burada kapanıyor',
            "Döngüyü kırmanın üç yolu:\n"
            . "  1. Ortak parçayı üçüncü bir servise çıkar (tercih edilen).\n"
            . "  2. Bir kenarı lazyRef() ile ertele ve cutEdge() ile BİLDİR:\n"
            . "       \$b->cutEdge(A::class, B::class, 'neden');\n"
            . "     Bildirilmiş kenar hata vermez; bildirilmemiş olan verir.\n"
            . "  3. Bağımlılığı constructor'dan çıkar, metot parametresi yap.",
            $chain[0] ?? null,
        );
    }

    /**
     * Zincir satırlarını lifetime ve kaynak konumu ile zenginleştirir —
     * hata mesajının işe yarar olmasını sağlayan kısım (DI-plan §29).
     *
     * @param list<string> $chain
     * @return list<string>
     */
    private function describeChain(DependencyGraph $graph, array $chain): array
    {
        $lines = [];
        $width = 0;

        foreach ($chain as $id) {
            $width = max($width, strlen($id));
        }

        foreach ($chain as $id) {
            $definition = $graph->definition($id);

            $line = str_pad($id, $width);

            if ($definition !== null) {
                $line .= '  (' . $definition->lifetime->label() . ')';
                if ($definition->source !== null) {
                    $line .= '  ' . $definition->source;
                }
            }

            $lines[] = rtrim($line);
        }

        return $lines;
    }
}
