<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Binding\BindingRegistry;
use System\Container\Definition\DefinitionRegistry;
use System\Engine\ModuleRegistry;

final class RootCollector
{
    private const SKIP_DIRS = ['views', 'cache', 'library'];

    /**
     * @param bool $discover
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly ClassScanner $scanner = new ClassScanner(),
        private readonly bool $discover = true,
    ) {}

    /**
     * @param list<string>|null $extraRoots
     * @return array<string, string>
     */
    public function collect(
        DefinitionRegistry $definitions,
        BindingRegistry $bindings,
        ?array $extraRoots = null,
    ): array {
        $roots = [];

        foreach (array_keys($definitions->sorted()) as $id) {
            $roots[$id] = 'tanım';
        }

        foreach (array_keys($bindings->sorted()) as $id) {
            $roots[$id] ??= 'binding';
        }

        if ($this->discover) {
            foreach ($this->controllers() as $class) {
                $roots[$class] ??= 'controller';
            }

            foreach ($this->commands() as $class) {
                $roots[$class] ??= 'komut';
            }

            foreach ($this->middleware() as $class) {
                $roots[$class] ??= 'middleware';
            }
        }

        foreach ($extraRoots ?? [] as $class) {
            $roots[$class] ??= 'ek kök';
        }

        ksort($roots);

        return $roots;
    }

    /**
     * @return list<string>
     */
    public function controllers(): array
    {
        $classes = [];

        foreach (ModuleRegistry::discover($this->appRoot) as $module) {
            $classes = [
                ...$classes,
                ...$this->scanner->scanDirectory(
                    ModuleRegistry::path($module, 'controllers', $this->appRoot),
                    self::SKIP_DIRS
                ),
            ];
        }

        return $this->existing($classes);
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return $this->existing($this->scanner->scanDirectory(
            $this->appRoot . '/system/console/commands',
            self::SKIP_DIRS
        ));
    }

    /**
     * @return list<string>
     */
    public function middleware(): array
    {
        $dirs = [$this->appRoot . '/system/middleware'];

        foreach (ModuleRegistry::discover($this->appRoot) as $module) {
            $dirs[] = ModuleRegistry::path($module, 'middleware', $this->appRoot);
        }

        $classes = [];
        foreach ($dirs as $dir) {
            $classes = [...$classes, ...$this->scanner->scanDirectory($dir, self::SKIP_DIRS)];
        }

        return $this->existing($classes);
    }

    /**
     * @return list<string>
     */
    public function allowedPrefixes(): array
    {
        $prefixes = ['System\\'];

        foreach (ModuleRegistry::discover($this->appRoot) as $module) {
            $prefixes[] = ModuleRegistry::namespace($module) . '\\';
        }

        return $prefixes;
    }

    public function isAllowed(string $class): bool
    {
        foreach ($this->allowedPrefixes() as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    private function existing(array $classes): array
    {
        $out = [];

        foreach (array_unique($classes) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                continue;
            }

            $out[] = $class;
        }

        sort($out);

        return $out;
    }
}
