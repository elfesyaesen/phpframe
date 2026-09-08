<?php

declare(strict_types=1);

namespace System\Routing;

use ReflectionClass;
use ReflectionMethod;
use System\Routing\Attributes\Route;
use System\Routing\Attributes\RouteGroup;
use System\Routing\Attributes\Middleware;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class AttributeScanner
{
    private array $scannedClasses = [];

    public function __construct(
        private readonly array $controllerPaths = [],
        private readonly array $controllerNamespaces = [],
    ) {}

    /**
     * Scan all controller paths and yield route definitions
     *
     * @return Generator<array>
     */
    public function scan(): Generator
    {
        foreach ($this->controllerPaths as $path => $namespace) {
            if (is_numeric($path)) {
                // Array format: [path1, path2] with auto-detected namespaces
                yield from $this->scanPath($namespace, $this->detectNamespace($namespace));
            } else {
                // Associative format: [path => namespace]
                yield from $this->scanPath($path, $namespace);
            }
        }

        // Also scan explicitly provided namespaces
        foreach ($this->controllerNamespaces as $namespace) {
            yield from $this->scanNamespace($namespace);
        }
    }

    /**
     * Scan a specific directory path for controllers
     */
    public function scanPath(string $path, string $namespace): Generator
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $phpFiles = new RegexIterator($iterator, '/\.php$/i');

        foreach ($phpFiles as $file) {
            $className = $this->resolveClassName($file->getPathname(), $path, $namespace);

            if ($className && class_exists($className)) {
                yield from $this->scanClass($className);
            }
        }
    }

    /**
     * Scan a specific class for route attributes
     */
    public function scanClass(string $className): Generator
    {
        // Prevent duplicate scanning
        if (isset($this->scannedClasses[$className])) {
            return;
        }
        $this->scannedClasses[$className] = true;

        try {
            $reflection = new ReflectionClass($className);
        } catch (\ReflectionException) {
            return;
        }

        // Skip abstract classes and interfaces
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return;
        }

        // Get class-level attributes
        $group = $this->getGroupAttribute($reflection);
        $classMiddleware = $this->getMiddlewareAttributes($reflection);

        // Scan public methods
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Skip magic methods and inherited methods from parent classes
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

            // Get route attributes
            $routeAttributes = $method->getAttributes(Route::class);

            foreach ($routeAttributes as $attribute) {
                /** @var Route $route */
                $route = $attribute->newInstance();

                yield $this->buildRouteDefinition(
                    route: $route,
                    group: $group,
                    classMiddleware: $classMiddleware,
                    methodMiddleware: $this->getMiddlewareAttributes($method),
                    controller: $className,
                    action: $method->getName(),
                );
            }
        }
    }

    /**
     * Scan by namespace (requires composer autoload)
     */
    private function scanNamespace(string $namespace): Generator
    {
        // This would require accessing composer's classmap
        // For now, we rely on path-based scanning
        return;
        yield;
    }

    /**
     * Get RouteGroup attribute from class
     */
    private function getGroupAttribute(ReflectionClass $class): ?RouteGroup
    {
        $attributes = $class->getAttributes(RouteGroup::class);

        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Get all Middleware attributes from class or method
     */
    private function getMiddlewareAttributes(ReflectionClass|ReflectionMethod $target): array
    {
        $middleware = [];
        $attributes = $target->getAttributes(Middleware::class);

        foreach ($attributes as $attribute) {
            /** @var Middleware $instance */
            $instance = $attribute->newInstance();
            $middleware = [...$middleware, ...$instance->toArray()];
        }

        return $middleware;
    }

    /**
     * Build a route definition array from attributes
     */
    private function buildRouteDefinition(
        Route $route,
        ?RouteGroup $group,
        array $classMiddleware,
        array $methodMiddleware,
        string $controller,
        string $action,
    ): array {
        // Build full path with group prefix
        $prefix = $group?->prefix ?? '';
        $path = $prefix
            ? rtrim($prefix, '/') . '/' . ltrim($route->path, '/')
            : $route->path;

        // Normalize path
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        // Merge middleware: group -> class -> route -> method
        $middleware = array_unique([
            ...($group?->middleware ?? []),
            ...$classMiddleware,
            ...$route->middleware,
            ...$methodMiddleware,
        ]);

        // Merge where constraints
        $where = [
            ...($group?->where ?? []),
            ...$route->where,
        ];

        return [
            'path' => $path,
            'uri' => $path, // Alias for compatibility
            'methods' => $route->methods,
            'method' => $route->methods[0] ?? 'GET',
            'action' => [$controller, $action],
            'name' => $route->name,
            'middleware' => $middleware,
            'where' => $where,
            'params' => $where, // Alias for compatibility
            'priority' => $route->priority,
            'source' => 'attribute',
        ];
    }

    /**
     * Resolve class name from file path
     */
    private function resolveClassName(string $filePath, string $basePath, string $baseNamespace): ?string
    {
        // Normalize paths
        $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath($filePath) ?: $filePath);
        $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath($basePath) ?: $basePath);

        // Get relative path
        $relativePath = str_replace($basePath, '', $filePath);
        $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

        // Remove .php extension
        $relativePath = preg_replace('/\.php$/i', '', $relativePath);

        // Convert path to namespace
        $relativeNamespace = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

        // Build full class name
        $className = rtrim($baseNamespace, '\\') . '\\' . $relativeNamespace;

        return $className;
    }

    /**
     * Auto-detect namespace from path (convention-based)
     */
    private function detectNamespace(string $path): string
    {
        // Try to detect from path structure
        // e.g., /app/Admin/Controllers -> Admin\Controllers
        $parts = explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));

        // Find 'Controllers' in path and build namespace
        $controllerIndex = array_search('Controllers', $parts);

        if ($controllerIndex !== false && $controllerIndex > 0) {
            $namespaceParts = array_slice($parts, $controllerIndex - 1);
            return implode('\\', $namespaceParts);
        }

        // Fallback: use last two directory parts
        $lastParts = array_slice($parts, -2);
        return implode('\\', array_filter($lastParts));
    }

    /**
     * Get list of scanned classes (for debugging)
     */
    public function getScannedClasses(): array
    {
        return array_keys($this->scannedClasses);
    }

    /**
     * Clear scanned classes cache (for re-scanning)
     */
    public function clearCache(): void
    {
        $this->scannedClasses = [];
    }
}
