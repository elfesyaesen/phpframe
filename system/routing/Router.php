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
        /**
         * CORS ve uygulama kökü artık global sabitlerden değil typed
         * config'ten okunur (DI-plan §25).
         */
        private readonly AppConfig $app,
        private readonly HttpConfig $http,
        private readonly ?RouteCacheInterface $cache = null,
        private readonly ?AttributeScanner $scanner = null,
        /**
         * Production'da false: cache geçerlilik hash'i, controller dizinlerini
         * recursive `stat`'lamaz (her request'te O(dosya) filesystem maliyeti).
         * Rotalar deploy'lar arası sabittir; değişiklik `frame cache:warm`/
         * `cache:clear` ile açıkça yenilenir. Dev'de true (otomatik algılama).
         */
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
        $currentGroup = end($this->groups) ?: [];
        $prefix = $currentGroup['prefix'] ?? '';

        $uri = $prefix ? rtrim($prefix, '/') . '/' . ltrim($uri, '/') : $uri;
        $uri = '/' . trim($uri, '/');

        $groupMiddleware = $currentGroup['middleware'] ?? [];
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

        // Invalidate tree cache when routes change
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

    /**
     * Dispatch request to matching route
     */
    public function dispatch(Request $request): void
    {
        $method = $request->method();

        // strtolower YOK: case normalizasyonu RadixTree'ye bırakılır (statik route'lar
        // case-insensitive eşleşir, dinamik parametreler orijinal case'ini korur).
        $uri = $this->resolveRoutePath($request);

        // CORS: her response icin ACAO/ACAC/Vary header'larini ekle.
        // Cross-origin GET/POST/... istekleri tarayicida bloklanmasin diye preflight olmayan
        // yollarda da gerekli.
        $this->applyCorsHeaders($request->header('Origin') ?? '');

        // Preflight: gercek route'a girmeden 204 ile cik.
        if ($method === 'OPTIONS') {
            $this->sendPreflightResponse();
            return;
        }

        // Use RadixTree for fast matching
        $tree = $this->getRouteTree();
        $match = $tree->match($method, $uri);

        if ($match) {
            $this->handleMatch($match, $request);
            return;
        }

        // Path başka metod(lar)da kayıtlıysa 404 değil 405 dönülür (HTTP semantiği).
        $allowed = $tree->allowedMethods($uri);
        if ($allowed !== []) {
            $this->handleMethodNotAllowed($uri, $method, $allowed);
        }

        $this->handleNotFound($uri, $method);
    }

    /**
     * İstek yolundan rota eşleştirmede kullanılacak temiz yolu çözer.
     *
     * Uygulamanın taban yolunu (front-controller'ın bulunduğu dizin, ör.
     * `/shop/public`) ve — rewrite yoksa — front-controller dosya adını
     * (`/index.php`) yalnızca BAŞTAN ayıklar. str_replace yerine önek-silme
     * kullanılır; böylece yolun ortasında geçen `index.php` ya da dizin adı
     * (dinamik parametre değerleri dahil) bozulmaz.
     *
     * SCRIPT_NAME örnekleri:
     *   /index.php            → taban '',        dosya /index.php
     *   /shop/index.php       → taban /shop,     dosya /index.php
     *   /shop/public/index.php→ taban /shop/public
     */
    private function resolveRoutePath(Request $request): string
    {
        $path = $request->path();
        $scriptName = $request->server('SCRIPT_NAME') ?? '';

        // 1. Taban yolu önekini kaldır (front-controller'ın dizini).
        $basePath = rtrim(dirname($scriptName), '/\\');
        if ($basePath !== '' && $basePath !== '/') {
            $path = $this->stripPrefix($path, $basePath);
        }

        // 2. Front-controller dosyası path'in başındaysa kaldır
        //    (rewrite yokken PATH_INFO biçimi: /index.php/users).
        $script = '/' . basename($scriptName);
        if ($script !== '/') {
            $path = $this->stripPrefix($path, $script);
        }

        // Leading/trailing '/' düzeltmesi RadixTree::normalizePath'e bırakılır.
        return $path === '' ? '/' : $path;
    }

    /**
     * $prefix yalnızca $path'in başındaysa ve ardından segment sınırı (`/` veya
     * yol sonu) geliyorsa kaldırır. `/shop` öneki `/shopfoo`'yu eşleştirmez.
     */
    private function stripPrefix(string $path, string $prefix): string
    {
        if (!str_starts_with($path, $prefix)) {
            return $path;
        }

        $remainder = substr($path, strlen($prefix));

        return ($remainder === '' || $remainder[0] === '/') ? $remainder : $path;
    }

    /**
     * Her cross-origin response'a eklenmesi gereken CORS header'lari.
     * Origin izin listesinde degilse hicbir header gonderilmez (tarayici response'u bloklar).
     */
    protected function applyCorsHeaders(string $origin): void
    {
        if ($origin === '') {
            return; // same-origin istek, CORS header'i gerekmez
        }

        $allowed = $this->getAllowedOrigins();
        $isWildcard = in_array('*', $allowed, true);

        // credentials=true ile ACAO=* kullanilamaz; bu yuzden wildcard'ta da
        // istekteki origin geri yansitilir.
        if (!$isWildcard && !in_array($origin, $allowed, true)) {
            return; // izinsiz origin
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    /**
     * Preflight (OPTIONS) icin ek header'lari yazip 204 doner.
     * applyCorsHeaders zaten dispatch'in basinda cagirildigi icin ACAO burada tekrar yazilmaz.
     */
    protected function sendPreflightResponse(): void
    {
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-Token');
        header('Access-Control-Max-Age: 86400');
        header('Content-Length: 0');
        header('Content-Type: text/plain');

        http_response_code(204);

        // `exit` KALDIRILDI. Gövde zaten yok (204 + Content-Length: 0), tek
        // gereken kontrolün çağırana dönmesi — `dispatch()` bu çağrıdan sonra
        // zaten `return` ediyor, dolayısıyla route eşleştirmesine girilmez.
    }

    /**
     * İzin verilen CORS origin'lerini al
     *
     * @return array<string>
     */
    protected function getAllowedOrigins(): array
    {
        // 1. Yapılandırılmış liste (CORS_ORIGINS)
        if ($this->http->corsOrigins !== []) {
            return $this->http->corsOrigins;
        }

        // 2. Varsayılan: yalnızca uygulamanın kendi URL'i.
        //
        // Eskiden burada bir de `getenv('CORS_ORIGINS')` dalı vardı; artık
        // gereksiz — HttpConfig zaten env'den okuyor, dolayısıyla aynı
        // kaynağı iki farklı yoldan okumak yalnızca tutarsızlık riski
        // üretiyordu (biri virgülle ayrılmış listeyi trim ediyor, diğeri
        // etmiyordu).
        $appUrl = $this->app->url;

        if ($appUrl === '') {
            return [];
        }

        $origins = [$appUrl];

        // HTTP ve HTTPS versiyonlarını ekle
        if (str_starts_with($appUrl, 'https://')) {
            $origins[] = str_replace('https://', 'http://', $appUrl);
        } elseif (str_starts_with($appUrl, 'http://')) {
            $origins[] = str_replace('http://', 'https://', $appUrl);
        }

        return $origins;
    }

    /**
     * Route ağacını kurup cache'e yazar ve kaydedilen route sayısını döndürür.
     *
     * `cache:warm` / `optimize` için: dispatch etmeden cache üretmenin tek
     * yolu. Eskiden bu komutlar cache'in yazıldığını yalnızca dosyanın VAR
     * OLMASINA bakarak varsayıyordu; önceki bir koşudan kalan dosya, hiçbir
     * şey yazılmamış olsa bile "başarılı" görünmesine yol açıyordu.
     *
     * Ağaç kurulumu attribute taramasını ve route dosyasını içerir,
     * dolayısıyla bu metot çağrılmadan cache dosyası oluşmaz.
     */
    public function warmCache(): int
    {
        // Ağaç HEM attribute route'larını HEM `$this->routes`'u içerir
        // (bkz. buildTree), dolayısıyla `count($this->routes)` eklemek
        // çifte sayım olur.
        return $this->getRouteTree()->count();
    }

    /**
     * Get or build the route tree
     */
    protected function getRouteTree(): RadixTree
    {
        // 1. Check memory cache
        if ($this->tree !== null) {
            return $this->tree;
        }

        // 2. Calculate routes hash for cache validation
        $hash = $this->calculateRoutesHash();

        // 3. Try to load from cache
        if ($this->cache !== null && $this->cache->isValid($hash)) {
            $cached = $this->cache->get();
            if ($cached !== null) {
                return $this->tree = $cached;
            }
        }

        // 4. Build new tree
        return $this->tree = $this->buildTree($hash);
    }

    /**
     * Build the RadixTree from all route sources
     */
    protected function buildTree(?string $hash = null): RadixTree
    {
        $tree = new RadixTree();

        // 1. Add attribute-based routes (priority)
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

        // 2. Add file-based routes (routes.php)
        foreach ($this->routes as $route) {
            $tree->insert($route['uri'], $route);
        }

        // 3. Cache the tree
        if ($this->cache !== null && $tree->count() > 0) {
            $this->cache->set($tree, $hash);
        }

        $this->logger->debug('Route tree built', [
            'route_count' => $tree->count(),
            'cached' => $this->cache !== null,
        ]);

        return $tree;
    }

    /**
     * Handle matched route
     *
     * Middleware'ler bir "onion" pipeline olarak çalışır: her middleware bir
     * sonrakini (ve nihayetinde controller'ı) saran $next handler'ını alır.
     * Devam etmek için $next çağrılmalı; çağrılmazsa zincir o noktada durur.
     */
    protected function handleMatch(RouteMatch $match, Request $request): void
    {
        $response = $this->container->get(Response::class);

        // Pipeline'ın ucu (controller invocation).
        $destination = function (Request $request) use ($match): void {
            $controller = $match->getController();
            $method = $match->getMethod();

            $controllerInstance = $this->container->get($controller);

            // Controller'lar framework servislerini constructor yerine setter
            // ile alır (temiz domain-only imzalar). Verilen şey artık
            // CONTAINER DEĞİL, bağımlılıkları bildirilmiş ControllerServices
            // façade'ı — service locator yüzeyi kapalı (bkz. ControllerServices).
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

        // Pipeline alias'ları container'dan değil MiddlewareRegistry'den
        // çözer: eşleme bildirimsel ve derleme zamanında doğrulanabilir.
        (new Pipeline($this->container->get(MiddlewareRegistry::class)))->run(
            middleware: $match->middleware,
            request: $request,
            response: $response,
            destination: $destination,
        );
    }

    /**
     * Handle route not found
     */
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
     * Handle method not allowed (path eşleşti ama metod farklı).
     *
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

    /**
     * Calculate hash for cache validation
     */
    protected function calculateRoutesHash(): string
    {
        if ($this->routesHash !== null) {
            return $this->routesHash;
        }

        $data = [];

        // Include routes.php modification time.
        // Route tanimlari routes/routes.php icinde (public/index.php bunu include eder).
        $routesFile = $this->app->path('routes', 'routes.php');
        if (file_exists($routesFile)) {
            $data['routes_file'] = filemtime($routesFile);
        }

        // Include controller directories modification times.
        // Production'da (validateControllerMtime=false) bu recursive stat atlanır;
        // cache yalnızca deploy-zamanı (cache:warm/clear) ile yenilenir.
        if ($this->validateControllerMtime && $this->scanner !== null) {
            foreach (RouterFactory::getDefaultControllerPaths($this->app->root) as $path => $_) {
                if (is_dir($path)) {
                    $data['controller_' . md5($path)] = $this->getDirectoryMtime($path);
                }
            }
        }

        // Include current routes array
        $data['routes'] = serialize($this->routes);

        return $this->routesHash = md5(serialize($data));
    }

    /**
     * Get latest modification time in directory (recursive)
     */
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

    /**
     * Clear route cache
     */
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

    /**
     * Get cache metadata (for debugging)
     */
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

    /**
     * Generate URL for named route
     */
    public function route(string $name, array $params = []): ?string
    {
        return $this->getRouteTree()->generate($name, $params);
    }

    /**
     * Get all registered routes (for debugging/listing)
     */
    public function getRoutes(): array
    {
        return $this->getRouteTree()->getRoutes();
    }
}
