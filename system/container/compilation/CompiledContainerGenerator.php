<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Binding\BindingRegistry;
use System\Container\Compiled\AbstractCompiledContainer;
use System\Container\Contract\CompilableFactoryInterface;
use System\Container\Definition\ArgumentKind;
use System\Container\Definition\ArgumentRef;
use System\Container\Definition\Definition;
use System\Container\Definition\FactoryKind;
use System\Container\Exception\FactoryException;
use System\Container\Lifetime\Lifetime;

/**
 * Bağımlılık grafiğini düz PHP koduna çevirir (DI-plan §16).
 *
 * Üretilen kodun tasarım kararları ve GEREKÇELERİ:
 *
 * • Sınıf adı GLOBAL namespace'te ve HASH SONEKLİ. Rolling deploy sırasında
 *   iki derlenmiş sürüm aynı OPcache'te yaşayabilir; sabit ad kullanılsaydı
 *   sıcak bir worker eski sınıfı tutarken yeni dosya require edilir ve
 *   production'da kurtarılamaz bir "duplicate class" fatal'i oluşurdu.
 *
 * • Metot adları okunabilir (`get_Api_Services_OrderService`). Stack trace ve
 *   profiler çıktısında hangi servisin kurulduğunu görmek, hash'li kısa
 *   adların kazandıracağı birkaç byte'tan kıyas kabul etmez şekilde değerli.
 *
 * • Her FQCN tam nitelikli ve baştan `\` ile yazılır → dosya `use` istemez,
 *   namespace bağlamından etkilenemez.
 *
 * • INTERFACE ID'LERİ DOĞRUDAN SOMUT SINIFIN METODUNA MAP'LENİR. Runtime'da
 *   interface lookup SIFIRDIR (DI-plan §11 son satırı).
 *
 * • Dönüş tipleri bildirilir → yanlış tip döndüren bir factory container'da
 *   değil, kendi sınırında gürültülü patlar.
 *
 * • Store anahtarları `::class` sabiti, elle yazılmış string değil → sınıf
 *   yeniden adlandırıldığında sessiz cache miss değil derleme hatası olur.
 */
final class CompiledContainerGenerator
{
    public function __construct(
        private readonly CodeWriter $writer = new CodeWriter(),
    ) {}

    /**
     * @param array<string, Definition> $definitions id'ye göre sıralı
     * @param BindingRegistry|null      $bindings    interface → somut kenarları;
     *        SERVICES map'ine alias satırı eklemek için gerekli (DI-plan §11)
     * @param array<string, string>     $meta        başlık yorumuna girecek bilgiler
     */
    public function generate(
        array $definitions,
        string $hash,
        array $meta = [],
        ?BindingRegistry $bindings = null,
    ): string {
        ksort($definitions);

        $className = self::className($hash);

        // Metot adları TÜM servisler için önceden üretilir: bir metot gövdesi
        // kendisinden sonra gelen bir servisi çağırabilir, o yüzden ad
        // ataması gövde üretiminden önce tamamlanmak zorunda (aksi halde
        // çakışma sonekleri sıraya bağlı olur ve çıktı byte-stabil olmaz).
        foreach (array_keys($definitions) as $id) {
            $this->writer->methodName($id);
        }

        $methods = [];
        foreach ($definitions as $id => $definition) {
            $methods[] = $this->method($id, $definition);
        }

        return $this->assemble(
            $className,
            $hash,
            $definitions,
            $methods,
            $meta,
            $this->aliasRows($definitions, $bindings),
        );
    }

    /**
     * Alias id'lerinin (interface'ler) SERVICES satırları.
     *
     * Closure geçişi tanımları ÇÖZÜLMÜŞ id ile saklar (Store yerine FileStore),
     * yani interface id'si kendiliğinden map'e girmez. Bu satırlar onu ekler ve
     * doğrudan somut sınıfın metoduna işaret eder.
     *
     * Sonuç DI-plan §11'in son satırıdır: `get(PaymentInterface::class)` bir
     * array okuması + bir metot çağrısı olur — runtime'da interface lookup,
     * binding zinciri yürüyüşü YOKTUR.
     *
     * @param array<string, Definition> $definitions
     * @return array<string, string> alias id => hedefin metot adı
     */
    private function aliasRows(array $definitions, ?BindingRegistry $bindings): array
    {
        if ($bindings === null) {
            return [];
        }

        $rows = [];

        foreach ($bindings->sorted() as $id => $binding) {
            if (isset($definitions[$id])) {
                continue; // kendi tanımı var, alias'a gerek yok
            }

            $target = $bindings->resolve($id);

            if (!isset($definitions[$target])) {
                continue; // hedef derlenmemiş; MISSING_DEPENDENCY raporlar
            }

            $rows[$id] = $this->writer->methodName($target);
        }

        ksort($rows);

        return $rows;
    }

    /**
     * Hash'ten deterministik sınıf adı.
     */
    public static function className(string $hash): string
    {
        return 'CompiledContainer_' . $hash;
    }

    // ── Metot üretimi ──────────────────────────────────────────────

    private function method(string $id, Definition $definition): string
    {
        $name = $this->writer->methodName($id);
        $returnType = $this->returnType($definition);
        $key = $this->writer->idKey($id);

        $doc = '/** ' . $definition->lifetime->label();
        if ($definition->source !== null) {
            $doc .= ' — ' . $definition->source;
        }
        $doc .= ' */';

        $body = match ($definition->lifetime) {
            Lifetime::INSTANCE  => $this->instanceBody($definition, $key),
            Lifetime::SINGLETON => $this->singletonBody($definition, $key),
            Lifetime::SCOPED    => $this->scopedBody($definition, $key),
            Lifetime::TRANSIENT => 'return ' . $this->construction($definition) . ';',
        };

        return $doc . "\n"
            . 'protected function ' . $name . '(): ' . $returnType . "\n"
            . "{\n"
            . $this->writer->indent($body, 1) . "\n"
            . '}';
    }

    /**
     * INSTANCE: ya dışarıdan verilen canlı obje, ya gömülü literal.
     */
    private function instanceBody(Definition $definition, string $key): string
    {
        if ($definition->isExternal()) {
            return 'return $this->external(' . $key . ');';
        }

        $literal = $definition->arguments[0] ?? null;

        if ($literal !== null && $literal->kind === ArgumentKind::LITERAL) {
            return 'return ' . $this->writer->export($literal->value, $definition->id) . ';';
        }

        return 'return $this->external(' . $key . ');';
    }

    /**
     * SINGLETON. Constructor tabanlı olanlar `??=` kullanır (`new` asla null
     * döndürmez). Factory tabanlı olanlar sharedFactory() kullanır — bir
     * factory meşru olarak null döndürebilir ve `??=` onu her çağrıda
     * yeniden üretirdi.
     */
    private function singletonBody(Definition $definition, string $key): string
    {
        if ($definition->isFactoryBacked()) {
            // `static` DEĞİL: factory çağrısı `$this` geçirir (container'ın
            // kendisi) ve static closure `$this`'i bind etmez —
            // "Using $this when not in object context" fatal'i verirdi.
            // Bu hata `php -l`'den geçer (sözdizimi geçerli) ve yalnızca
            // servis ilk çözüldüğünde ortaya çıkar.
            return 'return $this->sharedFactory(' . $key . ', fn(): mixed => '
                . $this->construction($definition) . ');';
        }

        return 'return $this->singletons[' . $key . '] ??= ' . $this->construction($definition) . ';';
    }

    /**
     * SCOPED. Aktif scope yoksa scopedInstance() hata fırlatır — sessiz
     * singleton terfisi ASLA yapılmaz.
     */
    private function scopedBody(Definition $definition, string $key): string
    {
        // Closure `$this`'i bind eder (servis çağrıları $this->get_X() olduğu
        // için static olamaz).
        return 'return $this->scopedInstance(' . $key . ', fn(): mixed => '
            . $this->construction($definition) . ');';
    }

    /**
     * Servisi kuran ifade: `new X(...)` veya factory çağrısı.
     */
    private function construction(Definition $definition): string
    {
        if ($definition->factory !== null) {
            return $this->factoryCall($definition);
        }

        if ($definition->arguments === []) {
            return 'new ' . $this->writer->fqcn($definition->concrete) . '()';
        }

        // Virgül, parametre adı yorumundan ÖNCE gelmek zorunda — yoksa
        // yorum virgülü yutar ve geçersiz PHP üretilir.
        $lines = [];
        foreach ($definition->arguments as $argument) {
            $lines[] = $this->argument($argument, $definition)
                . ', // $' . $argument->parameter;
        }

        return 'new ' . $this->writer->fqcn($definition->concrete) . "(\n"
            . $this->writer->indent(implode("\n", $lines), 1) . "\n"
            . ')';
    }

    private function factoryCall(Definition $definition): string
    {
        $factory = $definition->factory;

        if ($factory === null) {
            throw FactoryException::unsupported($definition->id, 'null');
        }

        return match ($factory->kind) {
            // Doğrudan static çağrı: indirection yok, closure allocation yok.
            FactoryKind::STATIC_METHOD => $this->writer->fqcn((string) $factory->class)
                . '::' . $factory->method . '($this)',

            // Factory'nin kendisi de derlenmiş bir servis → bağımlılıkları
            // doğrulanmış ve enjekte edilmiş olur.
            FactoryKind::INVOKABLE => '(' . $this->writer->serviceCall((string) $factory->class) . ')($this)',

            // Factory kendi ifadesini üretir; codegen kullanıcı kodunda kalır.
            FactoryKind::COMPILABLE => $this->compilableExpression($definition, (string) $factory->class),

            // Buraya gelinmemeli: FactoryValidator closure'ları hataya çevirir.
            FactoryKind::CLOSURE => throw FactoryException::closureNotCompilable(
                $definition->id,
                $factory->definedAt
            ),
        };
    }

    private function compilableExpression(Definition $definition, string $class): string
    {
        /** @var class-string<CompilableFactoryInterface> $class */
        return $class::compile($this->writer, $definition);
    }

    /**
     * Tek argümanı ifadeye çevirir.
     *
     * DİKKAT — bu match, Core\Container::resolveArgument() ile AYNI semantiği
     * taşımak ZORUNDA. Birine kol eklenip diğerine eklenmezse dev ve
     * production davranışı ayrışır ve bu, container'ın verebileceği en zor
     * bulunan hata sınıfıdır.
     */
    private function argument(ArgumentRef $argument, Definition $definition): string
    {
        return match ($argument->kind) {
            ArgumentKind::SERVICE => $this->writer->serviceCall((string) $argument->id),

            ArgumentKind::LITERAL => $this->writer->export(
                $argument->value,
                $definition->id . '::$' . $argument->parameter
            ),

            ArgumentKind::EXTERNAL => '$this->external('
                . $this->writer->idKey((string) $argument->id) . ')',

            ArgumentKind::CONTAINER => '$this',

            // Etiketli liste SABİT bir dizi ifadesine derlenir (DI-plan §20):
            // runtime'da etiket sorgusu, filtreleme veya arama YOKTUR —
            // yalnızca doğrudan çağrılardan oluşan bir literal dizi.
            ArgumentKind::TAGGED => $this->taggedArray($argument),

            // Dekoratörün sardığı iç katman — dahili id'nin kurucu metoduna
            // doğrudan çağrı. Zincir düzleştirildiği için runtime'da
            // dekoratör araması veya proxy yoktur (DI-plan §21).
            ArgumentKind::DECORATED => $this->writer->serviceCall((string) $argument->id),
        };
    }

    /**
     * Etiketli servis listesini sabit dizi ifadesine çevirir.
     */
    private function taggedArray(ArgumentRef $argument): string
    {
        $ids = $argument->ids ?? [];

        if ($ids === []) {
            return '[]';
        }

        $lines = [];
        foreach ($ids as $id) {
            $lines[] = '    ' . $this->writer->serviceCall($id) . ',';
        }

        return "[ // #" . $argument->id . "\n" . implode("\n", $lines) . "\n]";
    }

    private function returnType(Definition $definition): string
    {
        // Factory ve literal tanımlarında somut tip garanti edilemez.
        if ($definition->isFactoryBacked()) {
            return 'mixed';
        }

        if ($definition->lifetime === Lifetime::INSTANCE) {
            return $definition->isExternal() && class_exists($definition->concrete)
                ? $this->writer->fqcn($definition->concrete)
                : 'mixed';
        }

        return class_exists($definition->concrete)
            ? $this->writer->fqcn($definition->concrete)
            : 'mixed';
    }

    // ── Dosya iskeleti ─────────────────────────────────────────────

    /**
     * @param array<string, Definition> $definitions
     * @param list<string>              $methods
     * @param array<string, string>     $meta
     * @param array<string, string>     $aliases alias id => hedef metot adı
     */
    private function assemble(
        string $className,
        string $hash,
        array $definitions,
        array $methods,
        array $meta,
        array $aliases = [],
    ): string {
        $externals = [];
        foreach ($definitions as $id => $definition) {
            if ($definition->isExternal()) {
                $externals[$id] = true;
            }
        }

        $out = "<?php\n\n";
        $out .= "declare(strict_types=1);\n\n";
        $out .= "// ─────────────────────────────────────────────────────────────────────\n";
        $out .= "// `php frame container:compile` TARAFINDAN ÜRETİLDİ — ELLE DÜZENLEMEYİN.\n";
        $out .= "//\n";
        $out .= '// hash:     ' . $hash . "\n";
        $out .= '// servis:   ' . count($definitions) . "\n";
        foreach ($meta as $key => $value) {
            $out .= '// ' . str_pad($key . ':', 10) . $value . "\n";
        }
        $out .= "//\n";
        $out .= "// Runtime reflection: YOK. Graf yürüyüşü: YOK. Autowiring: YOK.\n";
        $out .= "// ─────────────────────────────────────────────────────────────────────\n\n";

        $out .= 'final class ' . $className . ' extends '
            . $this->writer->fqcn(AbstractCompiledContainer::class) . "\n";
        $out .= "{\n";
        $out .= "    public const HASH = " . var_export($hash, true) . ";\n\n";

        $out .= "    /** instance() ile verilmiş, kurulum sırasında dışarıdan gelmesi gereken servisler. */\n";
        $out .= '    public const EXTERNALS = ' . $this->writer->indent(
            $this->exportKeyedTrue($externals),
            1
        ) . ";\n\n";

        $out .= "    /** id => kurucu metot (DI-plan §17). Interface id'leri doğrudan somut metoda gider. */\n";
        $out .= '    protected const SERVICES = ' . $this->writer->indent(
            $this->exportServices(array_keys($definitions), $aliases),
            1
        ) . ";\n\n";

        $out .= $this->writer->indent(implode("\n\n", $methods), 1) . "\n";
        $out .= "}\n";

        return $out;
    }

    /**
     * @param array<string, true> $ids
     */
    private function exportKeyedTrue(array $ids): string
    {
        if ($ids === []) {
            return '[]';
        }

        $lines = [];
        foreach (array_keys($ids) as $id) {
            $lines[] = '    ' . $this->writer->idKey($id) . ' => true,';
        }

        return "[\n" . implode("\n", $lines) . "\n]";
    }

    /**
     * @param list<string>          $ids
     * @param array<string, string> $aliases alias id => hedef metot adı
     */
    private function exportServices(array $ids, array $aliases = []): string
    {
        /** @var array<string, string> id => metot adı */
        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = $this->writer->methodName($id);
        }

        foreach ($aliases as $id => $method) {
            $rows[$id] ??= $method;
        }

        if ($rows === []) {
            return '[]';
        }

        ksort($rows);

        $width = 0;
        $keys = [];
        foreach (array_keys($rows) as $id) {
            $key = $this->writer->idKey($id);
            $keys[$id] = $key;
            $width = max($width, strlen($key));
        }

        $lines = [];
        foreach ($rows as $id => $method) {
            $comment = isset($aliases[$id]) && !in_array($id, $ids, true)
                ? '  // alias → ' . $this->targetOfMethod($method, $ids)
                : '';

            $lines[] = '    ' . str_pad($keys[$id], $width) . ' => '
                . var_export($method, true) . ',' . $comment;
        }

        return "[\n" . implode("\n", $lines) . "\n]";
    }

    /**
     * Metot adından hedef servis id'sini bulur — alias satırlarına okunabilir
     * bir yorum eklemek için.
     *
     * @param list<string> $ids
     */
    private function targetOfMethod(string $method, array $ids): string
    {
        foreach ($ids as $id) {
            if ($this->writer->methodName($id) === $method) {
                return $id;
            }
        }

        return '?';
    }

    public function writer(): CodeWriter
    {
        return $this->writer;
    }
}
