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

/**
 * Bir sınıfı reflection ile Definition'a çevirir (DI-plan §9).
 *
 * Bu sınıf İKİ yerde kullanılır ve davranışı ikisinde de aynıdır:
 *   • dev runtime — ilk get()'te lazy çağrılır, istisnalar hemen fırlatılır
 *   • compiler    — erişilebilir tüm grafik için eager çağrılır, istisnalar
 *                   Diagnostic olarak TOPLANIR (tek koşuda tüm hatalar)
 *
 * Resolver'ın kendisi asla ertelemez; ertelemeyi çağıran belirler.
 *
 * Production'da bu sınıf HİÇ YÜKLENMEZ — derlenmiş container'da autowiring
 * yoktur (DI-plan §4, §37).
 */
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
     * @param list<string> $chain Çözümleme zinciri (hata mesajları için)
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

    /**
     * Açık tanımı olan ama argümanları henüz doldurulmamış bir Definition'ı
     * tamamlar.
     *
     * `bind()` reflection'a dokunmaz; argümanlar ilk ihtiyaçta burada
     * doldurulur. Factory destekli ve external tanımlar zaten kendi kendine
     * yeter — constructor analizine girmezler.
     */
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

    /**
     * Hiç tanımı olmayan bir sınıf için örtük (autowired) tanım üretir.
     *
     * Örtük tanımın lifetime'ı TRANSIENT'tir. Bu bilinçli: bir sınıfın
     * paylaşılması açık bir karardır. Örtük singleton, farkında olmadan
     * process-global state üretmenin en kolay yoludur.
     */
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
     * Constructor parametrelerini ArgumentRef listesine çevirir.
     *
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

        // Dekoratör katmanı mı? (DI-plan §21)
        $wrappedId = $definition === null ? null : DecoratorFlattener::wrappedIdOf($definition);
        $decoratedType = $wrappedId === null ? null : DecoratorFlattener::publicIdOf($wrappedId);
        $wrappedPlaced = false;

        $arguments = [];

        foreach ($this->reflection->constructorParameters($class) as $parameter) {
            // Dekoratörün SARDIĞI katman: dekore edilen tipe karşılık gelen
            // İLK parametreye yerleştirilir.
            //
            // Neden özel ele alınıyor: o parametrenin tipi dekore edilen
            // arayüzün kendisidir (`CacheInterface`), dolayısıyla normal
            // çözümleme dekoratörün KENDİSİNE geri döner ve sonsuz
            // özyineleme olur. Dahili id (`CacheInterface@inner.N`) bu
            // döngüyü kırar.
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
            // Dekoratör, sardığı tipi constructor'ında İSTEMİYOR — o zaman
            // dekoratör değil sadece bir yer değiştirmedir ve zincir sessizce
            // kopar (iç katman hiç kurulmaz).
            throw BindingException::decoratorMissingInner($class, $decoratedType);
        }

        return $arguments;
    }

    /**
     * Parametrenin tipi verilen tip mi (veya onun bir üst tipi mi)?
     *
     * Üst tip de kabul edilir: bir dekoratör `CacheInterface` yerine daha
     * geniş bir arayüz isteyebilir.
     */
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
