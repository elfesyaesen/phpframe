<?php

declare(strict_types=1);

namespace System\Routing;

final class RadixTree
{
    private array $static = [];
    private array $dynamic = [];
    private array $patterns = [];
    private array $names = [];
    private int $routeCount = 0;

    public function insert(string $path, array $route): void
    {
        $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
        $methods = is_string($methods) ? [$methods] : $methods;

        $normalizedPath = $this->normalizePath($path);

        foreach ($methods as $method) {
            $method = strtoupper($method);

            if ($this->isStaticPath($normalizedPath)) {
                $this->static[$method][$normalizedPath] = $route;
            } else {
                $segmentCount = substr_count($normalizedPath, '/');
                $pattern = $this->compilePattern($normalizedPath, $route['where'] ?? $route['params'] ?? []);

                $this->dynamic[$method][$segmentCount][] = [
                    'pattern' => $pattern,
                    'path' => $normalizedPath,
                    'route' => $route,
                ];
                $this->patterns[$normalizedPath] = $pattern;
            }
        }

        if (isset($route['name']) && $route['name'] !== null) {
            $this->names[$route['name']] = [
                'path' => $normalizedPath,
                'route' => $route,
            ];
        }

        $this->routeCount++;
    }

    public function match(string $method, string $path): ?RouteMatch
    {
        $method = strtoupper($method);

        $matchPath = $this->normalizePath($path, preserveCase: true);

        $staticKey = strtolower($matchPath);
        if (isset($this->static[$method][$staticKey])) {
            $route = $this->static[$method][$staticKey];
            return RouteMatch::fromArray($route);
        }

        $segmentCount = substr_count($matchPath, '/');

        if (isset($this->dynamic[$method][$segmentCount])) {
            foreach ($this->dynamic[$method][$segmentCount] as $entry) {
                if (preg_match($entry['pattern'], $matchPath, $matches)) {
                    $params = array_filter(
                        $matches,
                        fn($key) => !is_numeric($key),
                        ARRAY_FILTER_USE_KEY
                    );

                    return RouteMatch::fromArray($entry['route'], $params);
                }
            }
        }

        if ($method === 'HEAD') {
            return $this->match('GET', $path);
        }

        return null;
    }

    /**
     * @return array<string>
     */
    public function allowedMethods(string $path): array
    {
        $matchPath = $this->normalizePath($path, preserveCase: true);
        $staticKey = strtolower($matchPath);
        $segmentCount = substr_count($matchPath, '/');

        $methods = [];

        foreach ($this->static as $method => $paths) {
            if (isset($paths[$staticKey])) {
                $methods[$method] = true;
            }
        }

        foreach ($this->dynamic as $method => $segments) {
            if (!isset($segments[$segmentCount])) {
                continue;
            }

            foreach ($segments[$segmentCount] as $entry) {
                if (preg_match($entry['pattern'], $matchPath)) {
                    $methods[$method] = true;
                    break;
                }
            }
        }

        return array_keys($methods);
    }

    public function generate(string $name, array $params = []): ?string
    {
        if (!isset($this->names[$name])) {
            return null;
        }

        $path = $this->names[$name]['path'];

        foreach ($params as $key => $value) {
            $path = preg_replace('/\{' . $key . '(?::[^}]+)?\}/', (string) $value, $path);
        }

        if (preg_match('/\{[^}]+\}/', $path)) {
            return null;
        }

        return $path;
    }

    public function getRoutes(): array
    {
        $routes = [];

        foreach ($this->static as $method => $paths) {
            foreach ($paths as $path => $route) {
                $routes[] = [
                    'method' => $method,
                    'path' => $path,
                    'type' => 'static',
                    ...$route,
                ];
            }
        }

        foreach ($this->dynamic as $method => $segments) {
            foreach ($segments as $entries) {
                foreach ($entries as $entry) {
                    $routes[] = [
                        'method' => $method,
                        'path' => $entry['path'],
                        'type' => 'dynamic',
                        'pattern' => $entry['pattern'],
                        ...$entry['route'],
                    ];
                }
            }
        }

        return $routes;
    }

    public function count(): int
    {
        return $this->routeCount;
    }

    public function toArray(): array
    {
        return [
            'static' => $this->static,
            'dynamic' => $this->dynamic,
            'patterns' => $this->patterns,
            'names' => $this->names,
            'count' => $this->routeCount,
        ];
    }

    public static function fromArray(array $data): self
    {
        $tree = new self();
        $tree->static = $data['static'] ?? [];
        $tree->dynamic = $data['dynamic'] ?? [];
        $tree->patterns = $data['patterns'] ?? [];
        $tree->names = $data['names'] ?? [];
        $tree->routeCount = $data['count'] ?? 0;

        return $tree;
    }

    private function isStaticPath(string $path): bool
    {
        return !str_contains($path, '{');
    }

    private function normalizePath(string $path, bool $preserveCase = false): string
    {
        $path = '/' . trim($path, '/');

        if ($path === '/') {
            return '/';
        }

        return $preserveCase ? $path : strtolower($path);
    }

    private function compilePattern(string $path, array $constraints = []): string
    {
        $pattern = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            function ($matches) use ($constraints) {
                $param = $matches[1];
                $inlinePattern = $matches[2] ?? null;

                $regex = $inlinePattern
                    ?? $constraints[$param]
                    ?? '[^/]+';

                return '(?P<' . $param . '>' . $regex . ')';
            },
            $path
        );

        return '#^' . str_replace('/', '\\/', $pattern) . '$#i';
    }

    public function merge(RadixTree $other): void
    {
        foreach ($other->static as $method => $paths) {
            foreach ($paths as $path => $route) {
                $this->static[$method][$path] = $route;
            }
        }

        foreach ($other->dynamic as $method => $segments) {
            foreach ($segments as $count => $entries) {
                foreach ($entries as $entry) {
                    $this->dynamic[$method][$count][] = $entry;
                }
            }
        }

        foreach ($other->patterns as $path => $pattern) {
            $this->patterns[$path] = $pattern;
        }

        foreach ($other->names as $name => $data) {
            $this->names[$name] = $data;
        }

        $this->routeCount += $other->routeCount;
    }
}
