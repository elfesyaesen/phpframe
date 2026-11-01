<?php

declare(strict_types=1);

namespace System\Container\Definition;

use System\Container\Lifetime\Lifetime;

final readonly class Definition
{
    /**
     * @param class-string $id
     * @param class-string $concrete
     * @param list<ArgumentRef> $arguments
     * @param list<class-string> $dependsOn
     * @param bool $lazy
     * @param bool $autowired
     * @param string|null $source
     */
    public function __construct(
        public string $id,
        public string $concrete,
        public Lifetime $lifetime,
        public ?FactoryRef $factory = null,
        public array $arguments = [],
        public array $dependsOn = [],
        public bool $lazy = true,
        public bool $autowired = false,
        public ?string $source = null,
    ) {}

    /**
     * @param list<ArgumentRef> $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self(
            $this->id,
            $this->concrete,
            $this->lifetime,
            $this->factory,
            $arguments,
            $this->dependsOn,
            $this->lazy,
            $this->autowired,
            $this->source,
        );
    }

    public function withLifetime(Lifetime $lifetime): self
    {
        return new self(
            $this->id,
            $this->concrete,
            $lifetime,
            $this->factory,
            $this->arguments,
            $this->dependsOn,
            $this->lazy,
            $this->autowired,
            $this->source,
        );
    }

    /**
     * @return list<class-string>
     */
    public function edges(): array
    {
        if ($this->factory !== null) {
            return $this->dependsOn;
        }

        $edges = [];

        foreach ($this->arguments as $argument) {
            foreach ($argument->edges() as $target) {
                $edges[] = $target;
            }
        }

        return array_values(array_unique([...$edges, ...$this->dependsOn]));
    }

    public function isExternal(): bool
    {
        return $this->lifetime === Lifetime::INSTANCE
            && $this->arguments === [];
    }

    public function isFactoryBacked(): bool
    {
        return $this->factory !== null;
    }

    public function describeConstruction(): string
    {
        if ($this->factory !== null) {
            return $this->factory->describe();
        }

        if ($this->lifetime === Lifetime::INSTANCE) {
            return 'instance (external)';
        }

        return $this->concrete === $this->id ? 'new ' . $this->concrete : $this->concrete;
    }
}
