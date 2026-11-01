<?php

declare(strict_types=1);

namespace System\Container\Definition;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use System\Container\Contract\CompilableFactoryInterface;
use System\Container\Exception\FactoryException;
use Throwable;

final readonly class FactoryRef
{
    /**
     * @param class-string|null $class
     * @param string|null $method
     * @param Closure|null $closure
     * @param string|null $definedAt
     */
    public function __construct(
        public FactoryKind $kind,
        public ?string $class = null,
        public ?string $method = null,
        public ?Closure $closure = null,
        public ?string $definedAt = null,
    ) {}

    /**
     * @param callable|array{0:class-string,1:string}|class-string $factory
     */
    public static function from(callable|array|string $factory, string $id): self
    {
        if (is_array($factory)) {
            if (count($factory) !== 2 || !is_string($factory[0]) || !is_string($factory[1])) {
                throw FactoryException::unsupported($id, 'geçersiz array biçimi');
            }

            [$class, $method] = $factory;

            try {
                $reflection = new ReflectionMethod($class, $method);
            } catch (Throwable) {
                throw FactoryException::notCallable($class . '::' . $method);
            }

            if (!$reflection->isStatic()) {
                throw FactoryException::notStatic($class, $method);
            }

            return new self(FactoryKind::STATIC_METHOD, $class, $method);
        }

        if (is_string($factory)) {
            if (!class_exists($factory)) {
                throw FactoryException::unsupported($id, "'{$factory}' (sınıf bulunamadı)");
            }

            if (is_subclass_of($factory, CompilableFactoryInterface::class)) {
                return new self(FactoryKind::COMPILABLE, $factory);
            }

            if (method_exists($factory, '__invoke')) {
                return new self(FactoryKind::INVOKABLE, $factory);
            }

            if (method_exists($factory, 'create')) {
                if (!(new ReflectionMethod($factory, 'create'))->isStatic()) {
                    throw FactoryException::notStatic($factory, 'create');
                }
                return new self(FactoryKind::STATIC_METHOD, $factory, 'create');
            }

            throw FactoryException::notCallable($factory);
        }

        if ($factory instanceof Closure) {
            $reflection = new ReflectionFunction($factory);
            $file = $reflection->getFileName();

            return new self(
                FactoryKind::CLOSURE,
                closure: $factory,
                definedAt: $file !== false ? $file . ':' . $reflection->getStartLine() : null,
            );
        }

        throw FactoryException::unsupported($id, get_debug_type($factory));
    }

    public function isCompilable(): bool
    {
        return $this->kind->isCompilable();
    }

    public function describe(): string
    {
        return match ($this->kind) {
            FactoryKind::STATIC_METHOD => $this->class . '::' . $this->method . '()',
            FactoryKind::INVOKABLE     => $this->class . '::__invoke()',
            FactoryKind::COMPILABLE    => $this->class . '::compile()',
            FactoryKind::CLOSURE       => 'Closure @ ' . ($this->definedAt ?? '?'),
        };
    }
}
