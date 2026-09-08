<?php

declare(strict_types=1);

namespace System\Middleware;

use Closure;
use System\Http\Request;
use System\Http\Response;

/**
 * Middleware Pipeline (onion model)
 *
 * Verilen alias listesini iç içe geçmiş ("onion") bir handler zincirine çevirir:
 * en içte controller invocation (destination), her middleware bir öncekini sarar.
 * Her middleware $next handler'ını alır; devam etmek için onu çağırır, kısa devre
 * yapmak için çağırmaz.
 *
 * KISA DEVRE YOLU TEKTİR: `System\Exceptions\*` fırlatmak. Tüm middleware'ler
 * artık bunu kullanıyor — `AuthMiddleware` de dahil, ki o eskiden `Response`
 * ile yanıt verip `exit` ediyordu ve API'nin reddeden katmana göre iki farklı
 * hata zarfı döndürmesine sebep oluyordu.
 *
 * ── "AFTER" MIDDLEWARE ARTIK ÇALIŞIYOR ──────────────────────────────────
 *
 * Bu docblock eskiden şunu itiraf ediyordu: *"Response gönderimi exit eder,
 * dolayısıyla 'after' middleware mantığı yalnızca downstream hiç yanıt
 * göndermediyse çalışır."* `Response`'tan `exit` kaldırıldığı için bu kısıt
 * ortadan kalktı; `$next($request)` çağrısından SONRA yazılan kod artık her
 * durumda çalışır:
 *
 *     public function handle(Request $r, Response $res, Closure $next, ?string $p = null): void
 *     {
 *         $başlangıç = microtime(true);
 *         $next($r);
 *         $this->kaydet(microtime(true) - $başlangıç);   // ← artık ulaşılabilir
 *     }
 *
 * Uyarı: yanıt gövdesi `$next()` içinde YAZILMIŞ olur, dolayısıyla "after"
 * halkası gövdeyi değiştiremez — yalnızca ölçüm/temizlik yapabilir. Gövdeyi
 * dönüştürebilmek `Response`'ın bir DEĞER döndürmesini gerektirir; bu ayrı
 * bir adımdır.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class Pipeline
{
    /**
     * Alias çözümlemesi artık container'dan DEĞİL registry'den yapılır.
     *
     * Eskiden Pipeline container alıyor ve `Alias::resolve($alias, $container)`
     * çağırıyordu; yani hangi middleware sınıflarının var olduğu derleme
     * zamanında görünmüyordu ve yanlış yazılmış bir alias yalnızca o route'a
     * ilk istek geldiğinde patlıyordu (route bu arada korumasız kalabilir).
     * Registry eşlemeyi bildirimsel yapar ve derleyici hedefleri doğrular.
     */
    public function __construct(
        private readonly MiddlewareRegistry $registry,
    ) {}

    /**
     * Pipeline'ı kurup çalıştırır.
     *
     * @param array<string>         $middleware  'alias' veya 'alias:param' tanımları
     * @param Closure(Request): void $destination pipeline ucu (controller invocation)
     */
    public function run(array $middleware, Request $request, Response $response, Closure $destination): void
    {
        // Zinciri sondan başa sararak kur: array_reduce'un taşıdığı değer her zaman
        // "sonraki halka"dır; başlangıç değeri controller invocation'dır.
        $pipeline = array_reduce(
            array_reverse($middleware),
            fn (Closure $next, string $definition): Closure =>
                function (Request $request) use ($next, $response, $definition): void {
                    [$alias, $parameter] = MiddlewareRegistry::parse($definition);

                    $this->registry->resolve($alias)
                        ->handle($request, $response, $next, $parameter);
                },
            $destination,
        );

        $pipeline($request);
    }
}
