<?php

declare(strict_types=1);

namespace System\Container\Resolution;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

/**
 * Process başına ReflectionClass / constructor-parametre memo'su.
 *
 * Derleme reflection-yoğundur: 150 servisin kapalı grafiği yürünürken aynı
 * sınıf onlarca kez ziyaret edilir (bir Database'i 20 model ister). Aynı
 * ReflectionClass'ı yeniden kurmak derlemeyi gereksizce yavaşlatır.
 *
 * Runtime'da (dev) da kullanılır; production'da bu sınıf HİÇ yüklenmez —
 * derlenmiş container reflection katmanına dokunmaz.
 */
final class ReflectionCache
{
    /** @var array<string, ReflectionClass<object>|null> */
    private array $classes = [];

    /** @var array<string, list<ReflectionParameter>> */
    private array $parameters = [];

    /** @var array<string, bool> */
    private array $instantiable = [];

    /**
     * @return ReflectionClass<object>|null Sınıf yoksa veya reflect edilemiyorsa null
     */
    public function class(string $class): ?ReflectionClass
    {
        if (array_key_exists($class, $this->classes)) {
            return $this->classes[$class];
        }

        if (!class_exists($class) && !interface_exists($class)) {
            return $this->classes[$class] = null;
        }

        try {
            return $this->classes[$class] = new ReflectionClass($class);
        } catch (Throwable) {
            return $this->classes[$class] = null;
        }
    }

    public function isInstantiable(string $class): bool
    {
        return $this->instantiable[$class] ??= (bool) $this->class($class)?->isInstantiable();
    }

    public function constructor(string $class): ?ReflectionMethod
    {
        return $this->class($class)?->getConstructor();
    }

    /**
     * Constructor parametreleri. Constructor yoksa boş dizi — "parametresiz"
     * ile "constructor yok" ayrımı çağıran için önemli değil, ikisi de
     * `new $class()` demektir.
     *
     * @return list<ReflectionParameter>
     */
    public function constructorParameters(string $class): array
    {
        if (isset($this->parameters[$class])) {
            return $this->parameters[$class];
        }

        $constructor = $this->constructor($class);

        return $this->parameters[$class] = $constructor === null
            ? []
            : $constructor->getParameters();
    }

    /** Sınıfın tanımlandığı dosya — kaynak parmak izi (§31) ve CLI için. */
    public function fileName(string $class): ?string
    {
        $file = $this->class($class)?->getFileName();

        return $file === false ? null : $file;
    }

    public function clear(): void
    {
        $this->classes = [];
        $this->parameters = [];
        $this->instantiable = [];
    }
}
