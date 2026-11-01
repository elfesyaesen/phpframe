<?php

declare(strict_types=1);

namespace System\Engine;

use Twig\Loader\FilesystemLoader;

final class ViewPathRegistry
{
    /** @var array<string, list<string>> */
    private array $paths = [];

    public function __construct(
        private readonly FilesystemLoader $loader,
    ) {}

    public function add(string $path, string $namespace = FilesystemLoader::MAIN_NAMESPACE): void
    {
        if (!is_dir($path)) {
            return;
        }

        $registered = $this->paths[$namespace] ?? [];

        if (in_array($path, $registered, true)) {
            return;
        }

        $this->paths[$namespace][] = $path;
        $this->loader->addPath($path, $namespace);
    }

    public function loader(): FilesystemLoader
    {
        return $this->loader;
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->paths;
    }
}
