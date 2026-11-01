<?php

declare(strict_types=1);

namespace System\Container\Definition;

final readonly class ArgumentRef
{
    /**
     * @param list<string>|null $ids
     */
    private function __construct(
        public ArgumentKind $kind,
        public ?string $id,
        public mixed $value,
        public string $parameter,
        public ?array $ids = null,
    ) {}

    /** @param class-string $id */
    public static function service(string $id, string $parameter): self
    {
        return new self(ArgumentKind::SERVICE, $id, null, $parameter);
    }

    public static function literal(mixed $value, string $parameter): self
    {
        return new self(ArgumentKind::LITERAL, null, $value, $parameter);
    }

    /** @param class-string $id */
    public static function external(string $id, string $parameter): self
    {
        return new self(ArgumentKind::EXTERNAL, $id, null, $parameter);
    }

    public static function container(string $parameter): self
    {
        return new self(ArgumentKind::CONTAINER, null, null, $parameter);
    }

    /**
     * @param list<string> $ids
     */
    public static function tagged(string $tag, array $ids, string $parameter): self
    {
        return new self(ArgumentKind::TAGGED, $tag, null, $parameter, $ids);
    }

    public static function decorated(string $innerId, string $parameter): self
    {
        return new self(ArgumentKind::DECORATED, $innerId, null, $parameter);
    }

    public function isEdge(): bool
    {
        return $this->kind === ArgumentKind::SERVICE
            || $this->kind === ArgumentKind::DECORATED;
    }

    /**
     * @return list<string>
     */
    public function edges(): array
    {
        return match ($this->kind) {
            ArgumentKind::SERVICE, ArgumentKind::DECORATED => $this->id === null ? [] : [$this->id],
            ArgumentKind::TAGGED => $this->ids ?? [],
            default => [],
        };
    }

    public function describe(): string
    {
        return match ($this->kind) {
            ArgumentKind::SERVICE   => (string) $this->id,
            ArgumentKind::EXTERNAL  => $this->id . ' (external)',
            ArgumentKind::CONTAINER => 'ContainerInterface',
            ArgumentKind::LITERAL   => self::describeLiteral($this->value),
            ArgumentKind::TAGGED    => '#' . $this->id . ' (' . count($this->ids ?? []) . ' üye)',
            ArgumentKind::DECORATED => $this->id . ' (dekore edilen iç katman)',
        };
    }

    private static function describeLiteral(mixed $value): string
    {
        return match (true) {
            $value === null   => 'null',
            is_bool($value)   => $value ? 'true' : 'false',
            is_string($value) => "'" . (strlen($value) > 24 ? substr($value, 0, 21) . '...' : $value) . "'",
            is_array($value)  => 'array(' . count($value) . ')',
            is_object($value) => $value::class,
            default           => (string) $value,
        };
    }
}
