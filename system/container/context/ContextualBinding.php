<?php

declare(strict_types=1);

namespace System\Container\Context;

final readonly class ContextualBinding
{
    /**
     * @param class-string $consumer
     * @param string $needs
     * @param string|null $giveId
     * @param mixed $giveValue
     * @param bool $isValue
     * @param string|null $source
     */
    public function __construct(
        public string $consumer,
        public string $needs,
        public ?string $giveId = null,
        public mixed $giveValue = null,
        public bool $isValue = false,
        public ?string $source = null,
    ) {}

    public function matchesParameterName(): bool
    {
        return str_starts_with($this->needs, '$');
    }

    public function parameterName(): string
    {
        return ltrim($this->needs, '$');
    }

    public function describe(): string
    {
        $give = $this->isValue
            ? var_export($this->giveValue, true)
            : (string) $this->giveId;

        return $this->consumer . ' needs ' . $this->needs . ' → ' . $give;
    }
}
