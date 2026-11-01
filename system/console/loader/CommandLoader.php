<?php

declare(strict_types=1);

namespace System\Console\Loader;

use Generator;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use System\Console\Attributes\Command as CommandAttribute;
use System\Console\Command;

final class CommandLoader
{
    /** @var array<string, class-string> */
    private array $commandMap = [];

    /** @var array<string, Command> */
    private array $loadedCommands = [];

    /**
     * @param ContainerInterface|null $container
     */
    public function __construct(
        private readonly string $commandsPath,
        private readonly string $namespace = 'System\\Console\\Commands',
        private readonly ?ContainerInterface $container = null,
    ) {
        $this->scanCommands();
    }

    private function scanCommands(): void
    {
        if (!is_dir($this->commandsPath)) {
            return;
        }

        foreach (glob($this->commandsPath . '/*.php') as $file) {
            $className = $this->namespace . '\\' . basename($file, '.php');

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if (!$reflection->isInstantiable()) {
                continue;
            }

            if (!$reflection->isSubclassOf(Command::class)) {
                continue;
            }

            $attributes = $reflection->getAttributes(CommandAttribute::class);

            if (!empty($attributes)) {
                $this->register($attributes[0]->newInstance(), $className);
            }
        }
    }

    private function register(CommandAttribute $attr, string $className): void
    {
        foreach ([$attr->name, ...$attr->aliases] as $name) {
            $existing = $this->commandMap[$name] ?? null;

            if ($existing !== null && $existing !== $className) {
                throw new \LogicException(
                    "Komut adı çakışması: '{$name}' hem {$existing} hem {$className} tarafından tanımlanıyor."
                );
            }

            $this->commandMap[$name] = $className;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->commandMap[$name]);
    }

    public function get(string $name): Command
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException("Command '{$name}' not found.");
        }

        $className = $this->commandMap[$name];

        return $this->loadedCommands[$className] ??= $this->instantiate($className);
    }

    /**
     * @param class-string<Command> $className
     */
    private function instantiate(string $className): Command
    {
        if ($this->container === null) {
            return new $className();
        }

        /** @var Command $command */
        $command = $this->container->get($className);

        return $command;
    }

    /**
     * @return array<string>
     */
    public function getNames(): array
    {
        return array_keys($this->commandMap);
    }

    /**
     * @return Generator<Command>
     */
    public function getCommands(): Generator
    {
        $seen = [];

        foreach ($this->commandMap as $className) {
            if (isset($seen[$className])) {
                continue;
            }

            $seen[$className] = true;

            yield $this->loadedCommands[$className] ??= $this->instantiate($className);
        }
    }

    /**
     * @param array<string, string> $paths
     */
    public function addPaths(array $paths): void
    {
        foreach ($paths as $path => $namespace) {
            $this->scanPath($path, $namespace);
        }
    }

    private function scanPath(string $path, string $namespace): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*.php') as $file) {
            $className = $namespace . '\\' . basename($file, '.php');

            if (!class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if (!$reflection->isInstantiable() || !$reflection->isSubclassOf(Command::class)) {
                continue;
            }

            $attributes = $reflection->getAttributes(CommandAttribute::class);

            if (!empty($attributes)) {
                $this->register($attributes[0]->newInstance(), $className);
            }
        }
    }
}
