<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Definition\ExportGuard;
use System\Container\Exception\InvalidDefinitionException;

final class CodeWriter
{
    private const INDENT = '    ';

    /** @var array<string, string> */
    private array $methodNames = [];

    /** @var array<string, string> */
    private array $taken = [];

    public function methodName(string $id): string
    {
        if (isset($this->methodNames[$id])) {
            return $this->methodNames[$id];
        }

        $base = 'get_' . preg_replace('/[^A-Za-z0-9_]/', '_', $id);
        $name = $base;

        if (isset($this->taken[$name]) && $this->taken[$name] !== $id) {
            $name = $base . '_' . substr(hash('xxh128', $id), 0, 6);
        }

        $this->taken[$name] = $id;

        return $this->methodNames[$id] = $name;
    }

    public function serviceCall(string $id): string
    {
        return '$this->' . $this->methodName($id) . '()';
    }

    public function fqcn(string $class): string
    {
        return '\\' . ltrim($class, '\\');
    }

    public function classConst(string $class): string
    {
        return $this->fqcn($class) . '::class';
    }

    public function idKey(string $id): string
    {
        if (class_exists($id) || interface_exists($id) || enum_exists($id)) {
            return $this->classConst($id);
        }

        return $this->export($id);
    }

    public function export(mixed $value, string $context = 'değer'): string
    {
        $reason = ExportGuard::reject($value);

        if ($reason !== null) {
            throw InvalidDefinitionException::notExportable($context, $context, $reason);
        }

        if (is_array($value)) {
            return $this->exportArray($value, 0);
        }

        return var_export($value, true);
    }

    private function exportArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $pad = str_repeat(self::INDENT, $depth + 1);
        $closePad = str_repeat(self::INDENT, $depth);

        $parts = [];
        foreach ($value as $key => $item) {
            $rendered = is_array($item)
                ? $this->exportArray($item, $depth + 1)
                : var_export($item, true);

            $parts[] = $isList
                ? $pad . $rendered
                : $pad . var_export($key, true) . ' => ' . $rendered;
        }

        return "[\n" . implode(",\n", $parts) . ",\n" . $closePad . ']';
    }

    public function indent(string $code, int $level): string
    {
        $pad = str_repeat(self::INDENT, $level);

        return implode("\n", array_map(
            static fn(string $line): string => $line === '' ? '' : $pad . $line,
            explode("\n", $code)
        ));
    }

    /** @return array<string, string> */
    public function methodMap(): array
    {
        return $this->methodNames;
    }
}
