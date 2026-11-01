<?php

declare(strict_types=1);

namespace System\Container\Provider;

use ReflectionClass;
use ReflectionNamedType;
use System\Container\Contract\ContainerBuilderInterface;
use System\Engine\ModuleRegistry;

final class ModuleProvider extends AbstractServiceProvider
{
    private const DIRS = ['services', 'security'];

    public function register(ContainerBuilderInterface $builder): void
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);

        $services = [];

        foreach (ModuleRegistry::discover($root) as $module) {
            foreach (self::DIRS as $dir) {
                $files = glob(ModuleRegistry::path($module, $dir, $root) . '/*.php') ?: [];
                sort($files);

                foreach ($files as $file) {
                    $class = ModuleRegistry::namespace($module, ucfirst($dir)) . '\\' . basename($file, '.php');

                    if ($this->isService($class)) {
                        $services[] = $class;
                    }
                }
            }
        }

        foreach ($services as $class) {
            if (!$builder->has($class)) {
                $builder->scoped($class);
            }
        }

        foreach ($this->implementations($services) as $interface => $classes) {
            if (count($classes) === 1 && !$builder->has($interface)) {
                $builder->alias($interface, $classes[0]);
            }
        }
    }

    private function isService(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            return false;
        }

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($parameter->isDefaultValueAvailable()
                || ($type instanceof ReflectionNamedType && !$type->isBuiltin())
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $services
     * @return array<string, list<string>>
     */
    private function implementations(array $services): array
    {
        $map = [];

        foreach ($services as $class) {
            foreach (class_implements($class) as $interface) {
                if (!(new ReflectionClass($interface))->isInternal()) {
                    $map[$interface][] = $class;
                }
            }
        }

        return $map;
    }
}
