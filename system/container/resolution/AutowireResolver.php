<?php

declare(strict_types=1);

namespace System\Container\Resolution;

use System\Container\Binding\BindingRegistry;
use System\Container\Decorator\DecoratorFlattener;
use System\Container\Definition\ArgumentRef;
use System\Container\Definition\Definition;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Exception\BindingException;
use System\Container\Exception\NotFoundException;
use System\Container\Lifetime\Lifetime;

final class AutowireResolver
{
    public function __construct(
        private readonly DefinitionRegistry $definitions,
        private readonly BindingRegistry $bindings,
        private readonly ParameterResolver $parameters,
        private readonly ReflectionCache $reflection,
        private readonly bool $enabled = true,
    ) {}

    /**
     * @param list<string> $chain
     */
    public function resolve(string $id, array $chain = []): Definition
    {
        $resolved = $this->bindings->resolve($id);

        $existing = $this->definitions->get($resolved);
        if ($existing !== null) {
            return $this->complete($existing, $chain);
        }

        if (!$this->enabled) {
            throw BindingException::noDefinition($resolved, $chain);
        }

        return $this->complete($this->implicit($resolved, $chain), $chain);
    }

    public function complete(Definition $definition, array $chain = []): Definition
    {
        if ($definition->isFactoryBacked()
            || $definition->lifetime === Lifetime::INSTANCE
            || $definition->arguments !== []
        ) {
            return $definition;
        }

        return $definition->withArguments(
            $this->analyzeConstructor($definition->concrete, $chain, $definition)
        );
    }

    private function implicit(string $id, array $chain): Definition
    {
        if ($this->reflection->class($id) === null) {
            throw NotFoundException::for($id, $chain);
        }

        if (!$this->reflection->isInstantiable($id)) {
            throw BindingException::notInstantiable($id, $chain);
        }

        return new Definition(
            id: $id,
            concrete: $id,
            lifetime: Lifetime::TRANSIENT,
            autowired: true,
            source: 'autowired',
        );
    }

    /**
     * @param list<string> $chain
     * @return list<\System\Container\Definition\ArgumentRef>
     */
    public function analyzeConstructor(string $class, array $chain = [], ?Definition $definition = null): array
    {
        if ($this->reflection->class($class) === null) {
            throw NotFoundException::for($class, $chain);
        }

        if (!$this->reflection->isInstantiable($class)) {
            throw BindingException::notInstantiable($class, $chain);
        }

        $wrappedId = $definition === null ? null : DecoratorFlattener::wrappedIdOf($definition);
        $decoratedType = $wrappedId === null ? null : DecoratorFlattener::publicIdOf($wrappedId);
        $wrappedPlaced = false;

        $arguments = [];

        foreach ($this->reflection->constructorParameters($class) as $parameter) {
            if (!$wrappedPlaced && $decoratedType !== null
                && $this->parameterMatchesType($parameter, $decoratedType)
            ) {
                $arguments[] = ArgumentRef::decorated((string) $wrappedId, $parameter->getName());
                $wrappedPlaced = true;

                continue;
            }

            $arguments[] = $this->parameters->resolve($parameter, $class, $chain);
        }

        if ($decoratedType !== null && !$wrappedPlaced) {
            throw BindingException::decoratorMissingInner($class, $decoratedType);
        }

        return $arguments;
    }

    private function parameterMatchesType(\ReflectionParameter $parameter, string $type): bool
    {
        $parameterType = $parameter->getType();

        if (!$parameterType instanceof \ReflectionNamedType || $parameterType->isBuiltin()) {
            return false;
        }

        $name = $parameterType->getName();

        return $name === $type
            || is_a($type, $name, allow_string: true);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reflectionCache(): ReflectionCache
    {
        return $this->reflection;
    }
}
