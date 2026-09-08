<?php

declare(strict_types=1);

namespace System\Middleware\Interface;

use Closure;
use System\Http\Request;
use System\Http\Response;

interface MiddlewareInterface
{
    /**
     * Middleware "onion" pipeline halkası.
     *
     * Akış: middleware önce çalışır, devam etmek isterse $next($request) çağırır;
     * bu bir sonraki middleware'i (ve nihayetinde controller'ı) tetikler. $next
     * ÇAĞRILMAZSA zincir burada durur (kısa devre).
     *
     * İSTEĞİ REDDETMENİN TEK YOLU `System\Exceptions\*` FIRLATMAKTIR.
     *
     * Eskiden ikinci bir yol daha vardı — "$response ile yanıt gönderip exit
     * etmek" — ve kullanan tek middleware (`AuthMiddleware`) yüzünden API,
     * reddeden katmana göre İKİ FARKLI hata zarfı döndürüyordu. `Response`
     * artık `exit` etmediği için o yol yanıtı göndermekle kalır, zinciri
     * DURDURMAZ: istek controller'a akmaya devam eder. Reddetmek için
     * mutlaka fırlatın.
     *
     * `$next()` çağrısından SONRA kod yazılabilir ("after" mantığı); bkz.
     * `Pipeline` docblock'u.
     *
     * @param Request                $request   Sanitize edilmiş istek (input + header).
     * @param Response               $response  Yanıt göndericisi. Reddetme için KULLANMAYIN.
     * @param Closure(Request): void $next      Pipeline'ın geri kalanı; devam için çağrılmalı.
     * @param string|null            $parameter Alias'tan gelen ":param" (örn. 'api_role:admin').
     */
    public function handle(Request $request, Response $response, Closure $next, ?string $parameter = null): void;
}
