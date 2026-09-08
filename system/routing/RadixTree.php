<?php

declare(strict_types=1);

namespace System\Routing;

final class RadixTree
{
    /**
     * Tree structure:
     * [
     *     'static' => [METHOD => [path => route]],
     *     'dynamic' => [METHOD => [segment_count => [pattern => route]]],
     *     'patterns' => [path => compiled_regex],
     * ]
     */
    private array $static = [];
    private array $dynamic = [];
    private array $patterns = [];
    private array $names = [];
    private int $routeCount = 0;

    /**
     * Insert a route into the tree
     */
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

        // Store named routes for reverse routing
        if (isset($route['name']) && $route['name'] !== null) {
            $this->names[$route['name']] = [
                'path' => $normalizedPath,
                'route' => $route,
            ];
        }

        $this->routeCount++;
    }

    /**
     * Match a request against the route tree
     * Time complexity: O(1) for static, O(k) for dynamic where k = routes with same segment count
     */
    public function match(string $method, string $path): ?RouteMatch
    {
        $method = strtoupper($method);

        // Eşleştirme path'i ORİJİNAL case ile tutulur: yakalanan dinamik
        // parametrelerin (slug, base64, imza vb.) küçük harfe bozulmaması için.
        // Statik route'lar case-insensitive aranır (anahtarlar küçük harf saklanır);
        // dinamik pattern'ler zaten `i` flag'i ile derlendiğinden case-insensitive eşleşir.
        $matchPath = $this->normalizePath($path, preserveCase: true);

        // 1. Try static routes first (O(1) hash lookup) — case-insensitive
        $staticKey = strtolower($matchPath);
        if (isset($this->static[$method][$staticKey])) {
            $route = $this->static[$method][$staticKey];
            return RouteMatch::fromArray($route);
        }

        // 2. Try dynamic routes (O(k) where k = routes with same segment count)
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

        // 3. Try HEAD as GET fallback
        if ($method === 'HEAD') {
            return $this->match('GET', $path);
        }

        return null;
    }

    /**
     * Bir path'in (metod fark etmeksizin) eşleştiği tüm HTTP metodlarını döner.
     *
     * 405 Method Not Allowed üretimi için kullanılır: match() null dönerse ama
     * path başka metod(lar)da kayıtlıysa, 404 yerine 405 + Allow header gerekir.
     *
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

    /**
     * Generate URL for named route
     */
    public function generate(string $name, array $params = []): ?string
    {
        if (!isset($this->names[$name])) {
            return null;
        }

        $path = $this->names[$name]['path'];

        // Replace parameters
        foreach ($params as $key => $value) {
            $path = preg_replace('/\{' . $key . '(?::[^}]+)?\}/', (string) $value, $path);
        }

        // Check if all parameters were replaced
        if (preg_match('/\{[^}]+\}/', $path)) {
            return null; // Missing parameters
        }

        return $path;
    }

    /**
     * Get all routes (for debugging/listing)
     */
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

    /**
     * Get route count
     */
    public function count(): int
    {
        return $this->routeCount;
    }

    /**
     * Export tree to array (for caching)
     */
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

    /**
     * Create tree from array (from cache)
     */
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

    /**
     * Check if path is static (no parameters)
     */
    private function isStaticPath(string $path): bool
    {
        return !str_contains($path, '{');
    }

    /**
     * Normalize path (ensure leading slash, no trailing slash except root)
     *
     * Saklama (insert) için küçük harfe çevrilir; eşleştirme (match) için
     * $preserveCase=true ile orijinal case korunur — dinamik parametre değerleri
     * bozulmasın diye.
     */
    private function normalizePath(string $path, bool $preserveCase = false): string
    {
        $path = '/' . trim($path, '/');

        if ($path === '/') {
            return '/';
        }

        return $preserveCase ? $path : strtolower($path);
    }

    /**
     * Compile path pattern to regex
     */
    private function compilePattern(string $path, array $constraints = []): string
    {
        $pattern = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            function ($matches) use ($constraints) {
                $param = $matches[1];
                $inlinePattern = $matches[2] ?? null;

                // Priority: inline pattern > constraints > default
                $regex = $inlinePattern
                    ?? $constraints[$param]
                    ?? '[^/]+';

                return '(?P<' . $param . '>' . $regex . ')';
            },
            $path
        );

        return '#^' . str_replace('/', '\\/', $pattern) . '$#i';
    }

    /**
     * Merge another tree into this one
     */
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
