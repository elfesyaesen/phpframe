<?php

declare(strict_types=1);

namespace System\Container\Decorator;

use System\Container\Binding\BindingRegistry;
use System\Container\Definition\Definition;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Exception\BindingException;

final class DecoratorFlattener
{
    public static function apply(
        DefinitionRegistry $definitions,
        DecoratorRegistry $decorators,
        ?BindingRegistry $bindings = null,
    ): void {
        if ($decorators->isEmpty()) {
            return;
        }

        foreach ($decorators->all() as $id => $chain) {
            if ($definitions->has(DecoratorRegistry::innerId($id, 0))) {
                continue;
            }

            self::applyChain($definitions, $bindings, $id, $chain);
        }
    }

    /**
     * @param list<array{class: string, source: ?string}> $chain
     */
    private static function applyChain(
        DefinitionRegistry $definitions,
        ?BindingRegistry $bindings,
        string $id,
        array $chain,
    ): void {
        $target = $bindings?->resolve($id) ?? $id;
        $original = $definitions->get($target) ?? $definitions->get($id);

        if ($original === null) {
            throw BindingException::decoratingUnknown(
                $id,
                array_map(static fn(array $layer): string => $layer['class'], $chain),
            );
        }

        if ($target !== $id) {
            $bindings?->remove($id);
            $definitions->remove($target);
        }

        $innerId = DecoratorRegistry::innerId($id, 0);

        $definitions->set(new Definition(
            id: $innerId,
            concrete: $original->concrete,
            lifetime: $original->lifetime,
            factory: $original->factory,
            arguments: $original->arguments,
            dependsOn: $original->dependsOn,
            lazy: $original->lazy,
            autowired: $original->autowired,
            source: $original->source,
        ));

        $lastIndex = count($chain) - 1;

        foreach ($chain as $index => $layer) {
            $wraps = DecoratorRegistry::innerId($id, $index);

            $layerId = $index === $lastIndex
                ? $id
                : DecoratorRegistry::innerId($id, $index + 1);

            $definitions->set(new Definition(
                id: $layerId,
                concrete: $layer['class'],
                lifetime: $original->lifetime,

                arguments: [],
                dependsOn: [$wraps],
                autowired: false,
                source: $layer['source'],
            ));
        }
    }

    /**
     * @param list<string> $dependsOn
     */
    public static function wrappedIdOf(Definition $definition): ?string
    {
        foreach ($definition->dependsOn as $dependency) {
            if (str_contains($dependency, '@inner.')) {
                return $dependency;
            }
        }

        return null;
    }

    public static function publicIdOf(string $innerId): string
    {
        $position = strpos($innerId, '@inner.');

        return $position === false ? $innerId : substr($innerId, 0, $position);
    }
}
