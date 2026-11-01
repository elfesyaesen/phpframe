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
     * @return Generator<array>
     */
    public function scan(): Generator
    {
        foreach ($this->controllerPaths as $path => $namespace) {
            if (is_numeric($path)) {
                yield from $this->scanPath($namespace, $this->detectNamespace($namespace));
            } else {
                yield from $this->scanPath($path, $namespace);
            }
        }

        foreach ($this->controllerNamespaces as $namespace) {
            yield from $this->scanNamespace($namespace);
        }
    }

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

    public function scanClass(string $className): Generator
    {
        if (isset($this->scannedClasses[$className])) {
            return;
        }
        $this->scannedClasses[$className] = true;

        try {
            $reflection = new ReflectionClass($className);
        } catch (\ReflectionException) {
            return;
        }

        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return;
        }

        $group = $this->getGroupAttribute($reflection);
        $classMiddleware = $this->getMiddlewareAttributes($reflection);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), '__')) {
                continue;
            }

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

    private function scanNamespace(string $namespace): Generator
    {
        return;
        yield;
    }

    private function getGroupAttribute(ReflectionClass $class): ?RouteGroup
    {
        $attributes = $class->getAttributes(RouteGroup::class);

        if (empty($attributes)) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

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

    private function buildRouteDefinition(
        Route $route,
        ?RouteGroup $group,
        array $classMiddleware,
        array $methodMiddleware,
        string $controller,
        string $action,
    ): array {
        $prefix = $group?->prefix ?? '';
        $path = $prefix
            ? rtrim($prefix, '/') . '/' . ltrim($route->path, '/')
            : $route->path;

        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $middleware = array_unique([
            ...($group?->middleware ?? []),
            ...$classMiddleware,
            ...$route->middleware,
            ...$methodMiddleware,
        ]);

        $where = [
            ...($group?->where ?? []),
            ...$route->where,
        ];

        return [
            'path' => $path,
            'uri' => $path,
            'methods' => $route->methods,
            'method' => $route->methods[0] ?? 'GET',
            'action' => [$controller, $action],
            'name' => $route->name,
            'middleware' => $middleware,
            'where' => $where,
            'params' => $where,
            'priority' => $route->priority,
            'source' => 'attribute',
        ];
    }

    private function resolveClassName(string $filePath, string $basePath, string $baseNamespace): ?string
    {
        $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath($filePath) ?: $filePath);
        $basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath($basePath) ?: $basePath);

        $relativePath = str_replace($basePath, '', $filePath);
        $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

        $relativePath = preg_replace('/\.php$/i', '', $relativePath);

        $relativeNamespace = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

        $className = rtrim($baseNamespace, '\\') . '\\' . $relativeNamespace;

        return $className;
    }

    private function detectNamespace(string $path): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));

        $controllerIndex = array_search('Controllers', $parts);

        if ($controllerIndex !== false && $controllerIndex > 0) {
            $namespaceParts = array_slice($parts, $controllerIndex - 1);
            return implode('\\', $namespaceParts);
        }

        $lastParts = array_slice($parts, -2);
        return implode('\\', array_filter($lastParts));
    }

    public function getScannedClasses(): array
    {
        return array_keys($this->scannedClasses);
    }

    public function clearCache(): void
    {
        $this->scannedClasses = [];
    }
}
