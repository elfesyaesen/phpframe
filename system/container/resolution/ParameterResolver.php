<?php

declare(strict_types=1);

namespace System\Container\Resolution;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use System\Container\Attribute\Config;
use System\Container\Attribute\Inject;
use System\Container\Attribute\Named;
use System\Container\Attribute\Tagged;
use System\Container\Binding\BindingRegistry;
use System\Container\Context\ContextualRegistry;
use System\Container\Contract\ContainerInterface as FrameContainerInterface;
use System\Container\Core\Container;
use System\Container\Tag\TagRegistry;
use System\Container\Definition\ArgumentRef;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Definition\ExportGuard;
use System\Container\Exception\InvalidDefinitionException;
use System\Container\Exception\UnresolvableDependencyException;
use Throwable;

final class ParameterResolver
{
    private const CONTAINER_TYPES = [
        PsrContainerInterface::class => true,
        FrameContainerInterface::class => true,
        Container::class => true,
    ];

    public function __construct(
        private readonly BindingRegistry $bindings,
        private readonly ReflectionCache $reflection,
        private readonly ?DefinitionRegistry $definitions = null,
        private readonly ?ContextualRegistry $contextual = null,
        private readonly ?TagRegistry $tags = null,
    ) {}

    /**
     * @param string $consumer
     * @param list<string> $chain
     */
    public function resolve(ReflectionParameter $parameter, string $consumer, array $chain = []): ArgumentRef
    {
        $name = $parameter->getName();

        $contextual = $this->resolveContextual($parameter, $consumer);
        if ($contextual !== null) {
            return $contextual;
        }

        $attributed = $this->resolveAttribute($parameter, $consumer);
        if ($attributed !== null) {
            return $attributed;
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            if (isset(self::CONTAINER_TYPES[$typeName])) {
                return ArgumentRef::container($name);
            }

            $target = $this->bindings->resolve($typeName);

            if ($type->allowsNull()
                && $parameter->isDefaultValueAvailable()
                && !$this->isKnown($target, $typeName)
            ) {
                return $this->resolveScalar($parameter, $consumer, $chain);
            }

            return ArgumentRef::service($target, $name);
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            return $this->resolveComposite($type, $parameter, $consumer, $chain);
        }

        return $this->resolveScalar($parameter, $consumer, $chain);
    }

    private function isKnown(string $resolved, string $original): bool
    {
        return $this->bindings->has($original)
            || $this->definitions?->has($resolved) === true
            || $this->definitions?->has($original) === true;
    }

    private function resolveScalar(ReflectionParameter $parameter, string $consumer, array $chain): ArgumentRef
    {
        $name = $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            try {
                $default = $parameter->getDefaultValue();
            } catch (Throwable $e) {
                throw InvalidDefinitionException::unexportableDefault(
                    $consumer,
                    $name,
                    'default değer okunamadı: ' . $e->getMessage(),
                );
            }

            $reason = ExportGuard::reject($default);
            if ($reason !== null) {
                throw InvalidDefinitionException::unexportableDefault($consumer, $name, $reason);
            }

            return ArgumentRef::literal($default, $name);
        }

        if ($parameter->isVariadic()) {
            return ArgumentRef::literal([], $name);
        }

        $type = $parameter->getType();

        throw UnresolvableDependencyException::primitive(
            $consumer,
            $name,
            $type instanceof ReflectionNamedType ? $type->getName() : null,
            $chain,
        );
    }

    private function resolveComposite(
        ReflectionUnionType|ReflectionIntersectionType $type,
        ReflectionParameter $parameter,
        string $consumer,
        array $chain,
    ): ArgumentRef {
        $name = $parameter->getName();
        $candidates = [];

        foreach ($type->getTypes() as $member) {
            if (!$member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            $resolved = $this->bindings->resolve($member->getName());

            $isKnown = $this->bindings->has($member->getName())
                || $this->definitions?->has($resolved) === true;

            if ($isKnown) {
                $candidates[] = $resolved;
            }
        }

        $candidates = array_values(array_unique($candidates));

        if (count($candidates) === 1) {
            return ArgumentRef::service($candidates[0], $name);
        }

        if ($candidates === [] && $parameter->isDefaultValueAvailable()) {
            return $this->resolveScalar($parameter, $consumer, $chain);
        }

        throw UnresolvableDependencyException::ambiguousType($consumer, $name, $candidates, $chain);
    }

    private function resolveContextual(ReflectionParameter $parameter, string $consumer): ?ArgumentRef
    {
        if ($this->contextual === null || !$this->contextual->hasConsumer($consumer)) {
            return null;
        }

        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType && !$type->isBuiltin()
            ? $type->getName()
            : null;

        $binding = $this->contextual->find($consumer, $typeName, $parameter->getName());

        if ($binding === null) {
            return null;
        }

        if ($binding->isValue) {
            $reason = ExportGuard::reject($binding->giveValue);

            if ($reason !== null) {
                throw InvalidDefinitionException::notExportable(
                    $consumer,
                    'when(' . $consumer . ')->needs(' . $binding->needs . ')->give(...)',
                    $reason,
                );
            }

            return ArgumentRef::literal($binding->giveValue, $parameter->getName());
        }

        return ArgumentRef::service(
            $this->bindings->resolve((string) $binding->giveId),
            $parameter->getName(),
        );
    }

    private function resolveAttribute(ReflectionParameter $parameter, string $consumer): ?ArgumentRef
    {
        $name = $parameter->getName();

        foreach ($parameter->getAttributes(Inject::class) as $attribute) {
            /** @var Inject $inject */
            $inject = $attribute->newInstance();

            return ArgumentRef::service($this->bindings->resolve($inject->id), $name);
        }

        foreach ($parameter->getAttributes(Tagged::class) as $attribute) {
            /** @var Tagged $tagged */
            $tagged = $attribute->newInstance();

            if ($this->tags === null || !$this->tags->has($tagged->tag)) {
                throw UnresolvableDependencyException::unknownTag(
                    $consumer,
                    $name,
                    $tagged->tag,
                    $this->tags?->names() ?? [],
                );
            }

            return ArgumentRef::tagged(
                $tagged->tag,
                array_map(
                    fn(string $id): string => $this->bindings->resolve($id),
                    $this->tags->members($tagged->tag),
                ),
                $name,
            );
        }

        foreach ($parameter->getAttributes(Named::class) as $attribute) {
            /** @var Named $named */
            $named = $attribute->newInstance();

            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw UnresolvableDependencyException::namedWithoutType($consumer, $name, $named->name);
            }

            return ArgumentRef::service(
                $this->bindings->resolve(Named::idFor($type->getName(), $named->name)),
                $name,
            );
        }

        foreach ($parameter->getAttributes(Config::class) as $attribute) {
            /** @var Config $config */
            $config = $attribute->newInstance();

            return ArgumentRef::service($config->key, $name);
        }

        return null;
    }
}
