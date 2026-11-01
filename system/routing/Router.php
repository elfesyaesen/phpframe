<?php

declare(strict_types=1);

namespace System\Routing;

use Psr\Container\ContainerInterface;
use System\Config\AppConfig;
use System\Config\HttpConfig;
use System\Engine\ControllerServices;
use System\Engine\ServicesAwareInterface;
use System\Middleware\MiddlewareRegistry;
use System\Exceptions\MethodNotAllowedException;
use System\Exceptions\NotFoundException;
use System\Http\Request;
use System\Http\Response;
use System\Logging\Contracts\LoggerInterface;
use System\Middleware\Pipeline;
use System\Routing\Cache\RouteCacheInterface;

class Router
{
    protected array $routes = [];
    protected array $groups = [];
    private ?RadixTree $tree = null;
    private ?string $routesHash = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
        private readonly AppConfig $app,
        private readonly HttpConfig $http,
        private readonly ?RouteCacheInterface $cache = null,
        private readonly ?AttributeScanner $scanner = null,
        private readonly bool $validateControllerMtime = true,
    ) {}

    public function group(array $attributes, callable $callback): void
    {
        $this->groups[] = $attributes;
        $callback($this);
        array_pop($this->groups);
    }

    public function get(string $uri, array $action): self
    {
        return $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, array $action): self
    {
        return $this->addRoute('POST', $uri, $action);
    }

    public function put(string $uri, array $action): self
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    public function patch(string $uri, array $action): self
    {
        return $this->addRoute('PATCH', $uri, $action);
    }

    public function delete(string $uri, array $action): self
    {
        return $this->addRoute('DELETE', $uri, $action);
    }

    public function any(string $uri, array $action): self
    {
        return $this->addRoute($_SERVER['REQUEST_METHOD'], $uri, $action);
    }

    protected function addRoute(string $method, string $uri, array $action): self
    {
        $prefix = '';
        $groupMiddleware = [];
        foreach ($this->groups as $group) {
            $prefix .= '/' . trim($group['prefix'] ?? '', '/');
            $groupMiddleware = [...$groupMiddleware, ...(array) ($group['middleware'] ?? [])];
        }

        $uri = '/' . trim(preg_replace('#/+#', '/', $prefix . '/' . $uri) ?? '', '/');

        $routeMiddleware = $action['middleware'] ?? [];
        $combinedMiddleware = [...$groupMiddleware, ...$routeMiddleware];

        $params = $action['params'] ?? [];
        $queryParams = [];
        if (str_contains($uri, '?')) {
            [$uri, $queryString] = explode('?', $uri, 2);
            parse_str($queryString, $queryParams);
            $params = array_merge($params, $queryParams);
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'uri' => $uri,
            'action' => $action,
            'name' => $action['name'] ?? null,
            'middleware' => $combinedMiddleware,
            'params' => $params,
            'source' => 'file',
        ];

        $this->tree = null;

        return $this;
    }

    public function name(string $name): self
    {
        $key = array_key_last($this->routes);
        if ($key !== null) {
            $this->routes[$key]['name'] = $name;
        }

        return $this;
    }

    public function middleware(array|string $middleware): self
    {
        $key = array_key_last($this->routes);
        if ($key !== null) {
            $middleware = is_string($middleware) ? [$middleware] : $middleware;
            $this->routes[$key]['middleware'] = [
                ...$this->routes[$key]['middleware'],
                ...$middleware,
            ];
        }

        return $this;
    }

    public function params(array $params): self
    {
        $key = array_key_last($this->routes);
        if ($key !== null) {
            $this->routes[$key]['params'] = $params;
        }

        return $this;
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();

        $uri = $this->resolveRoutePath($request);

        $this->applyCorsHeaders($request->header('Origin') ?? '');

        if ($method === 'OPTIONS') {
            $this->sendPreflightResponse();
            return;
        }

        $tree = $this->getRouteTree();
        $match = $tree->match($method, $uri);

        if ($match) {
            $this->handleMatch($match, $request);
            return;
        }

        $allowed = $tree->allowedMethods($uri);
        if ($allowed !== []) {
            $this->handleMethodNotAllowed($uri, $method, $allowed);
        }

        $this->handleNotFound($uri, $method);
    }

    private function resolveRoutePath(Request $request): string
    {
        $path = $request->path();
        $scriptName = $request->server('SCRIPT_NAME') ?? '';

        $basePath = rtrim(dirname($scriptName), '/\\');
        if ($basePath !== '' && $basePath !== '/') {
            $path = $this->stripPrefix($path, $basePath);
        }

        $script = '/' . basename($scriptName);
        if ($script !== '/') {
            $path = $this->stripPrefix($path, $script);
        }

        return $path === '' ? '/' : $path;
    }

    private function stripPrefix(string $path, string $prefix): string
    {
        if (!str_starts_with($path, $prefix)) {
            return $path;
        }

        $remainder = substr($path, strlen($prefix));

        return ($remainder === '' || $remainder[0] === '/') ? $remainder : $path;
    }

    protected function applyCorsHeaders(string $origin): void
    {
        if ($origin === '') {
            return;
        }

        $allowed = $this->getAllowedOrigins();
        $isWildcard = in_array('*', $allowed, true);

        if (!$isWildcard && !in_array($origin, $allowed, true)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    protected function sendPreflightResponse(): void
    {
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
        header('Content-Length: 0');
        header('Content-Type: text/plain');

        http_response_code(204);
    }

    /**
     * @return array<string>
     */
    protected function getAllowedOrigins(): array
    {
        if ($this->http->corsOrigins !== []) {
            return $this->http->corsOrigins;
        }

        $origin = HttpConfig::normalizeOrigin($this->app->url);

        return $origin === null ? [] : [$origin];
    }

    public function warmCache(): int
    {
        return $this->getRouteTree()->count();
    }

    protected function getRouteTree(): RadixTree
    {
        if ($this->tree !== null) {
            return $this->tree;
        }

        $hash = $this->calculateRoutesHash();

        if ($this->cache !== null && $this->cache->isValid($hash)) {
            $cached = $this->cache->get();
            if ($cached !== null) {
                return $this->tree = $cached;
            }
        }

        return $this->tree = $this->buildTree($hash);
    }

    protected function buildTree(?string $hash = null): RadixTree
    {
        $tree = new RadixTree();

        if ($this->scanner !== null) {
            foreach ($this->scanner->scan() as $route) {
                foreach ($route['methods'] as $method) {
                    $tree->insert($route['path'], [
                        ...$route,
                        'method' => $method,
                    ]);
                }
            }
        }

        foreach ($this->routes as $route) {
            $tree->insert($route['uri'], $route);
        }

        if ($this->cache !== null && $tree->count() > 0) {
            $this->cache->set($tree, $hash);
        }

        $this->logger->debug('Route tree built', [
            'route_count' => $tree->count(),
            'cached' => $this->cache !== null,
        ]);

        return $tree;
    }

    protected function handleMatch(RouteMatch $match, Request $request): void
    {
        $response = $this->container->get(Response::class);

        $destination = function (Request $request) use ($match): void {
            $controller = $match->getController();
            $method = $match->getMethod();

            $controllerInstance = $this->container->get($controller);

            if ($controllerInstance instanceof ServicesAwareInterface) {
                $controllerInstance->setServices($this->container->get(ControllerServices::class));
            }

            if (!method_exists($controllerInstance, $method)) {
                throw new NotFoundException(
                    message: "Method '{$method}' controller '{$controller}' içinde bulunamadı",
                    context: [
                        'controller' => $controller,
                        'method' => $method,
                    ]
                );
            }

            call_user_func_array([$controllerInstance, $method], $match->params);
        };

        $request->setMatchedRoute($match->method, $match->path, $match->name);

        (new Pipeline($this->container->get(MiddlewareRegistry::class)))->run(
            middleware: $match->middleware,
            request: $request,
            response: $response,
            destination: $destination,
        );
    }

    protected function handleNotFound(string $uri, string $method): never
    {
        throw new NotFoundException(
            message: 'Rota bulunamadı',
            context: [
                'uri' => $uri,
                'method' => $method,
            ]
        );
    }

    /**
     * @param array<string> $allowed
     */
    protected function handleMethodNotAllowed(string $uri, string $method, array $allowed): never
    {
        throw new MethodNotAllowedException(
            allowedMethods: $allowed,
            context: [
                'uri' => $uri,
                'method' => $method,
            ]
        );
    }

    protected function calculateRoutesHash(): string
    {
        if ($this->routesHash !== null) {
            return $this->routesHash;
        }

        $data = [];

        $routesFile = $this->app->path('routes', 'routes.php');
        if (file_exists($routesFile)) {
            $data['routes_file'] = filemtime($routesFile);
        }

        if ($this->validateControllerMtime && $this->scanner !== null) {
            foreach (RouterFactory::getDefaultControllerPaths($this->app->root) as $path => $_) {
                if (is_dir($path)) {
                    $data['controller_' . md5($path)] = $this->getDirectoryMtime($path);
                }
            }
        }

        $data['routes'] = serialize($this->routes);

        return $this->routesHash = md5(serialize($data));
    }

    private function getDirectoryMtime(string $path): int
    {
        $mtime = filemtime($path);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $mtime = max($mtime, $file->getMTime());
            }
        }

        return $mtime;
    }

    public function clearCache(): void
    {
        $this->tree = null;
        $this->routesHash = null;

        if ($this->cache !== null) {
            $this->cache->clear();
        }

        if ($this->scanner !== null) {
            $this->scanner->clearCache();
        }

        $this->logger->info('Route cache cleared');
    }

    public function getCacheInfo(): array
    {
        return [
            'driver' => $this->cache?->getMeta()['driver'] ?? 'none',
            'tree_loaded' => $this->tree !== null,
            'file_routes' => count($this->routes),
            'total_routes' => $this->tree?->count() ?? 0,
            ...$this->cache?->getMeta() ?? [],
        ];
    }

    public function route(string $name, array $params = []): ?string
    {
        return $this->getRouteTree()->generate($name, $params);
    }

    public function getRoutes(): array
    {
        return $this->getRouteTree()->getRoutes();
    }
}
