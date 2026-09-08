<?php

declare(strict_types=1);

namespace System\Console\Loader;

use Generator;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use System\Console\Attributes\Command as CommandAttribute;
use System\Console\Command;

/**
 * Command auto-discovery loader
 */
final class CommandLoader
{
    /** @var array<string, class-string> */
    private array $commandMap = [];

    /** @var array<string, Command> */
    private array $loadedCommands = [];

    /**
     * @param ContainerInterface|null $container Verilirse komutlar container
     *        tarafından çözülür ve CONSTRUCTOR INJECTION kullanabilir.
     *
     * Eskiden `new $className()` çağrılıyordu, yani komutlar bağımlılık
     * alamıyordu ve her biri kendi servisini elle kuruyordu — örneğin altı
     * ayrı komutta `new Database(new NullRecorder())`. Bu, hem yapılandırmayı
     * (kimin hangi recorder'ı verdiğini) çoğaltıyor hem de komutları
     * container'ın doğrulamasının dışında bırakıyordu.
     *
     * Container geçilmezse eski davranış (argümansız `new`) korunur; bu,
     * container kurulmadan çalışan erken boot yolları için gerekli.
     */
    public function __construct(
        private readonly string $commandsPath,
        private readonly string $namespace = 'System\\Console\\Commands',
        private readonly ?ContainerInterface $container = null,
    ) {
        $this->scanCommands();
    }

    /**
     * Komutları tara ve map oluştur
     */
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

            // Abstract veya interface ise atla
            if (!$reflection->isInstantiable()) {
                continue;
            }

            // Command base class'tan türetilmeli
            if (!$reflection->isSubclassOf(Command::class)) {
                continue;
            }

            // Attribute'tan komut adını al
            $attributes = $reflection->getAttributes(CommandAttribute::class);

            if (!empty($attributes)) {
                $attr = $attributes[0]->newInstance();
                $commandName = $attr->name;

                $this->commandMap[$commandName] = $className;

                // Alias'ları da ekle
                foreach ($attr->aliases as $alias) {
                    $this->commandMap[$alias] = $className;
                }
            }
        }
    }

    /**
     * Komut var mı?
     */
    public function has(string $name): bool
    {
        return isset($this->commandMap[$name]);
    }

    /**
     * Komutu yükle
     */
    public function get(string $name): Command
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException("Command '{$name}' not found.");
        }

        // Daha önce yüklenmişse cache'ten döndür
        $className = $this->commandMap[$name];

        return $this->loadedCommands[$className] ??= $this->instantiate($className);
    }

    /**
     * Komutu container ile (varsa) veya doğrudan kurar.
     *
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
     * Tüm komut adlarını döndür
     *
     * @return array<string>
     */
    public function getNames(): array
    {
        return array_keys($this->commandMap);
    }

    /**
     * Tüm benzersiz komut sınıflarını döndür.
     *
     * Bu, yardım listesi (`frame list`) için TÜM komutları örnekler.
     * Bağımlılıkları olan komutlar için bu yalnızca artık güvenli:
     * `Database`, `Schema` ve `MigrationRunner` bağlantıyı LAZY açıyor
     * (bkz. Database::getConnection), dolayısıyla komutları kurmak hiçbir
     * I/O üretmez. Eskiden `Database` constructor'da bağlanıyordu; komutlar
     * container'dan çözülse `frame list` her çağrısında MySQL'e bağlanırdı.
     *
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
     * Ek dizinleri tara
     *
     * @param array<string, string> $paths [path => namespace]
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
                $attr = $attributes[0]->newInstance();
                $this->commandMap[$attr->name] = $className;

                foreach ($attr->aliases as $alias) {
                    $this->commandMap[$alias] = $className;
                }
            }
        }
    }
}
