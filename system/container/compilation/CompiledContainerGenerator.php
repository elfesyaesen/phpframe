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

final class CompiledContainerGenerator
{
    public function __construct(
        private readonly CodeWriter $writer = new CodeWriter(),
    ) {}

    /**
     * @param array<string, Definition> $definitions
     * @param BindingRegistry|null $bindings
     * @param array<string, string> $meta
     */
    public function generate(
        array $definitions,
        string $hash,
        array $meta = [],
        ?BindingRegistry $bindings = null,
    ): string {
        ksort($definitions);

        $className = self::className($hash);

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
     * @param array<string, Definition> $definitions
     * @return array<string, string>
     */
    private function aliasRows(array $definitions, ?BindingRegistry $bindings): array
    {
        if ($bindings === null) {
            return [];
        }

        $rows = [];

        foreach ($bindings->sorted() as $id => $binding) {
            if (isset($definitions[$id])) {
                continue;
            }

            $target = $bindings->resolve($id);

            if (!isset($definitions[$target])) {
                continue;
            }

            $rows[$id] = $this->writer->methodName($target);
        }

        ksort($rows);

        return $rows;
    }

    public static function className(string $hash): string
    {
        return 'CompiledContainer_' . $hash;
    }

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

    private function singletonBody(Definition $definition, string $key): string
    {
        if ($definition->isFactoryBacked()) {
            return 'return $this->sharedFactory(' . $key . ', fn(): mixed => '
                . $this->construction($definition) . ');';
        }

        return 'return $this->singletons[' . $key . '] ??= ' . $this->construction($definition) . ';';
    }

    private function scopedBody(Definition $definition, string $key): string
    {
        return 'return $this->scopedInstance(' . $key . ', fn(): mixed => '
            . $this->construction($definition) . ');';
    }

    private function construction(Definition $definition): string
    {
        if ($definition->factory !== null) {
            return $this->factoryCall($definition);
        }

        if ($definition->arguments === []) {
            return 'new ' . $this->writer->fqcn($definition->concrete) . '()';
        }

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
            FactoryKind::STATIC_METHOD => $this->writer->fqcn((string) $factory->class)
                . '::' . $factory->method . '($this)',

            FactoryKind::INVOKABLE => '(' . $this->writer->serviceCall((string) $factory->class) . ')($this)',

            FactoryKind::COMPILABLE => $this->compilableExpression($definition, (string) $factory->class),

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

            ArgumentKind::TAGGED => $this->taggedArray($argument),

            ArgumentKind::DECORATED => $this->writer->serviceCall((string) $argument->id),
        };
    }

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

    /**
     * @param array<string, Definition> $definitions
     * @param list<string> $methods
     * @param array<string, string> $meta
     * @param array<string, string> $aliases
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
     * @param list<string> $ids
     * @param array<string, string> $aliases
     */
    private function exportServices(array $ids, array $aliases = []): string
    {
        /** @var array<string, string> */
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
