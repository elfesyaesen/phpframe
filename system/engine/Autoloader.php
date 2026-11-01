<?php

declare(strict_types=1);

namespace System\Engine;

class Autoloader
{
    /**
     * @var array<string, string>
     */
    private const PREFIXES = [];

    public function __construct()
    {
        spl_autoload_register(function (string $class): void {
            $this->load($class);
        });
    }

    public function load(string $class): bool
    {
        foreach (self::PREFIXES as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                return $this->requireFile(
                    $this->prefixedPath(substr($class, strlen($prefix)), $base)
                );
            }
        }

        $parts = explode('\\', $class);

        $className = array_pop($parts);

        $namespacePath = strtolower(implode('/', $parts));

        return $this->requireFile(
            APP_ROOT . DIRECTORY_SEPARATOR . $namespacePath . DIRECTORY_SEPARATOR . $className . '.php'
        );
    }

    private function prefixedPath(string $relativeClass, string $base): string
    {
        $parts = explode('\\', $relativeClass);
        $className = array_pop($parts);

        $dir = $parts === [] ? '' : strtolower(implode('/', $parts)) . '/';

        return APP_ROOT . '/' . $base . $dir . $className . '.php';
    }

    private function requireFile(string $filePath): bool
    {
        if (is_file($filePath)) {
            require_once $filePath;
            return true;
        }

        return false;
    }
}