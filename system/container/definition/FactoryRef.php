<?php

declare(strict_types=1);

namespace System\Container\Definition;

use Closure;
use ReflectionFunction;
use ReflectionMethod;
use System\Container\Contract\CompilableFactoryInterface;
use System\Container\Exception\FactoryException;
use Throwable;

/**
 * Bir factory'ye REFERANS — callable'ın kendisi değil.
 *
 * Bu ayrım DI-plan §16'nın ("compiled container'da reflection = 0") kilit
 * taşıdır. Container'da callable saklamak, o callable'ı derlenmiş dosyaya
 * yazamamak anlamına gelir; referans saklamak ise doğrudan static çağrıya
 * derlenebilir demektir.
 *
 * Closure'a izin verilir ama YALNIZCA dev'de: geliştirici closure yazar,
 * `container:validate` ona tam kaynak satırıyla nasıl static metoda
 * dönüştüreceğini söyler. Göç yolu budur.
 */
final readonly class FactoryRef
{
    /**
     * @param class-string|null $class
     * @param string|null       $method
     * @param Closure|null      $closure   YALNIZCA CLOSURE türünde; asla persist edilmez
     * @param string|null       $definedAt Closure'ın tanım yeri (file:line) — hata mesajı için
     */
    public function __construct(
        public FactoryKind $kind,
        public ?string $class = null,
        public ?string $method = null,
        public ?Closure $closure = null,
        public ?string $definedAt = null,
    ) {}

    /**
     * Verilen factory ifadesini normalize eder.
     *
     * @param callable|array{0:class-string,1:string}|class-string $factory
     */
    public static function from(callable|array|string $factory, string $id): self
    {
        // [Sınıf::class, 'metot']
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

        // Sınıf adı: compilable > invokable > static create()
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

        // Closure — dev'de çalışır, derlemede reddedilir.
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

    /** CLI ve hata mesajları için kısa gösterim. */
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
