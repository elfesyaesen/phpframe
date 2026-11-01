<?php

declare(strict_types=1);

namespace System\Container\Contract;

use System\Container\Binding\BindingRegistry;
use System\Container\Context\ContextualBinding;
use System\Container\Context\ContextualRegistry;
use System\Container\Core\ContextualBindingBuilder;
use System\Container\Decorator\DecoratorRegistry;
use System\Container\Definition\DefinitionRegistry;
use System\Container\Definition\LazyRef;
use System\Container\Lifetime\Lifetime;
use System\Container\Tag\TagRegistry;

interface ContainerBuilderInterface
{
    /**
     * @param class-string $id
     * @param class-string|null $concrete
     */
    public function bind(string $id, ?string $concrete = null, Lifetime $lifetime = Lifetime::TRANSIENT): static;

    public function singleton(string $id, ?string $concrete = null): static;

    public function scoped(string $id, ?string $concrete = null): static;

    public function transient(string $id, ?string $concrete = null): static;

    /**
     * @param class-string $id
     * @param callable|array{0:class-string,1:string}|class-string $factory
     * @param list<class-string> $dependsOn
     */
    public function factory(
        string $id,
        callable|array|string $factory,
        Lifetime $lifetime = Lifetime::SINGLETON,
        array $dependsOn = [],
    ): static;

    /**
     * @param class-string $id
     */
    public function instance(string $id, object $value): static;

    public function literal(string $id, mixed $value): static;

    /**
     * @param class-string $id
     * @param class-string $target
     */
    public function alias(string $id, string $target): static;

    public function lazyRef(string $id): LazyRef;

    public function cutEdge(string $from, string $to, string $reason): static;

    /**
     * @param class-string $consumer
     */
    public function when(string $consumer): ContextualBindingBuilder;

    public function addContextualBinding(ContextualBinding $binding): static;

    /**
     * @param list<class-string> $ids
     */
    public function tag(array $ids, string $tag): static;

    /**
     * @param class-string $id
     * @param class-string $decorator
     */
    public function decorate(string $id, string $decorator): static;

    public function autowire(bool $enabled = true): static;

    public function lock(): static;

    public function isLocked(): bool;

    public function has(string $id): bool;

    public function definitions(): DefinitionRegistry;

    public function bindings(): BindingRegistry;

    /**
     * @return array<class-string, object>
     */
    public function externals(): array;

    /**
     * @return array<string, array{from: string, to: string, reason: string}>
     */
    public function cutEdges(): array;

    public function contextual(): ContextualRegistry;

    public function tags(): TagRegistry;

    public function decorators(): DecoratorRegistry;

    public function build(): ContainerInterface;
}
