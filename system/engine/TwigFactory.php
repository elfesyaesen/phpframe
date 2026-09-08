<?php

declare(strict_types=1);

namespace System\Engine;

use RuntimeException;

use System\Config\AppConfig;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;

/**
 * Twig ortamını kurar.
 *
 * ARTIK STATIC SINGLETON DEĞİL. Eski hâlinde `private static ?Environment
 * $instance` ve `private static ?FilesystemLoader $loader` tutuyordu, yani:
 *
 *   • Ortam süreç geneli paylaşılıyordu ve container'ın bilgisi dışındaydı —
 *     lifetime yönetimi DI dışında kalıyordu.
 *   • `addPath()` static olduğu için `BaseController::view()` bağımlılığını
 *     imzasında bildirmeden şablon yolu ekliyordu (service locator'ın
 *     static hâli).
 *   • `APP_PRODUCTION` / `APP_ROOT` sabitlerini okuyordu; sabitler
 *     silindiğinden zaten dokunulmak zorundaydı.
 *
 * Artık normal bir servis: container `Environment`'ı singleton olarak tutar,
 * yollar `ViewPathRegistry` üzerinden yönetilir (bkz. ViewProvider).
 */
final class TwigFactory
{
    public function __construct(
        private readonly AppConfig $app,
        private readonly ViewPathRegistry $paths,
    ) {}

    public function create(): Environment
    {
        $production = $this->app->production;

        $twig = new Environment($this->paths->loader(), [
            'cache' => $production ? $this->app->path('system', 'cache', 'twig') : false,
            'debug' => !$production,
            'charset' => 'UTF-8',
            'strict_variables' => !$production,
            'auto_reload' => !$production,
        ]);

        if (!$production) {
            $twig->addExtension(new DebugExtension());
        }

        $this->addCsrfFunctions($twig);

        return $twig;
    }

    /**
     * CSRF Twig fonksiyonlari.
     *
     * ─────────────────────────────────────────────────────────────────────
     * DİKKAT — CSRF BU PROJEDE UYGULANMAMIŞ.
     *
     * Bu fonksiyonlar eskiden `System\Security\Csrf::token()` çağırıyordu
     * ama O SINIF PROJEDE YOK (`MonitorController` docblock'u da bunu
     * kaydediyor). Yani bir şablon `{{ csrf_token() }}` yazsa
     * "Class not found" fatal'i alırdı — sebebi hiçbir yerde yazmayan,
     * teşhisi zor bir hata.
     *
     * Sınıfı yazmak bir TEMİZLİK değil ÖZELLİK kararıdır: CSRF token'ı
     * sunucu tarafı durum (session) veya double-submit cookie gerektirir;
     * bu API ise stateless JWT kullanıyor ve hiçbir yerde `session_start()`
     * çağırmıyor. Hangi modelin seçileceği uygulamanın kararı.
     *
     * Bu yüzden fonksiyonlar KALDIRILMADI (kaldırılsa Twig "Unknown
     * function" der ve bu da nedeni anlatmaz) ama artık AÇIK bir mesajla
     * patlıyorlar. Form tabanlı bir ekran eklenecekse yapılacak iş bu
     * mesajda yazıyor.
     * ─────────────────────────────────────────────────────────────────────
     */
    private function addCsrfFunctions(Environment $twig): void
    {
        $notImplemented = static function (string $function): never {
            throw new RuntimeException(
                "[CSRF_NOT_IMPLEMENTED] Twig fonksiyonu '{$function}()' çağrıldı "
                . "ancak CSRF korumasi bu projede UYGULANMAMIŞ.\n\n"
                . "  Bu API stateless JWT kullanıyor: session yok, dolayısıyla\n"
                . "  klasik session-tabanlı CSRF token'ı da yok.\n\n"
                . "  Form tabanlı (tarayıcı oturumu ile çalışan) bir ekran\n"
                . "  eklemek için yapılacaklar:\n"
                . "    1. `System\\Security\\Csrf` sınıfını yaz (token üretimi +\n"
                . "       doğrulama). Stateless bir API için double-submit\n"
                . "       cookie deseni oturum gerektirmez.\n"
                . "    2. Bir provider'da servis olarak kaydet.\n"
                . "    3. TwigFactory'ye enjekte et ve bu fonksiyonları ona bağla.\n"
                . "    4. POST route'larına doğrulama middleware'i ekle."
            );
        };

        // {{ csrf_token() }} - Sadece token degerini dondurur
        $twig->addFunction(new TwigFunction(
            'csrf_token',
            static fn(): string => $notImplemented('csrf_token'),
        ));

        // {{ csrf_field()|raw }} - Hidden input field dondurur
        $twig->addFunction(new TwigFunction(
            'csrf_field',
            static fn(): string => $notImplemented('csrf_field'),
            ['is_safe' => ['html']],
        ));

        // {{ csrf_meta()|raw }} - Meta tag dondurur (JavaScript icin)
        $twig->addFunction(new TwigFunction(
            'csrf_meta',
            static fn(): string => $notImplemented('csrf_meta'),
            ['is_safe' => ['html']],
        ));
    }
}
