<?php

declare(strict_types=1);

namespace System\Engine;

use System\Exceptions\BadRequestException;
use System\Http\Request;
use System\Http\Response;
use Twig\Environment;
use System\Security\Contract\GateInterface;
use System\Translation\Contract\TranslatorInterface;

abstract class BaseController implements ServicesAwareInterface
{
    protected array $data = [];

    /**
     * View modül path'i için güvenli format (whitelist YOK — herhangi bir modül).
     * Tek segment; küçük harfle başlar; harf/rakam/alt çizgi. Slash/nokta/backslash
     * içermediğinden path traversal yapısal olarak imkânsızdır.
     */
    private const MODULE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Controller'ın kullanabileceği servis kümesi.
     *
     * ESKİSİ: private bir `Container` tutuluyor ve accessor'lar ondan servis
     * çekiyordu. Daraltma amacı doğruydu ama container tutmanın bedeli vardı:
     * controller'ın gerçek bağımlılıkları hiçbir yerde BİLDİRİLMİYORDU, yani
     * derleyici onları doğrulayamıyor ve `container:debug` sıfır bağımlılık
     * gösteriyordu (bkz. ControllerServices).
     *
     * YENİSİ: bağımlılıkları bildirilmiş, scoped, readonly bir façade.
     * Accessor yüzeyi AYNI kaldı — controller'lar değişmedi — ama artık
     * hiçbir yerde container yok.
     */
    private ?ControllerServices $services = null;

    public function setServices(ControllerServices $services): void
    {
        $this->services = $services;
    }

    /**
     * İç erişim; set edilmemişse net hata fırlatır.
     */
    private function services(): ControllerServices
    {
        if ($this->services === null) {
            throw new \RuntimeException(
                'ControllerServices set edilmedi: Router, controller\'ı resolve ettikten '
                . 'sonra setServices() çağırmalı.'
            );
        }

        return $this->services;
    }

    protected function request(): Request
    {
        return $this->services()->request;
    }

    protected function response(): Response
    {
        return $this->services()->response;
    }

    protected function twig(): Environment
    {
        return $this->services()->twig;
    }

    protected function translator(): TranslatorInterface
    {
        return $this->services()->translator;
    }

    /**
     * Yetkilendirme kapısı (RBAC). Aktif kullanıcının rol/yetkilerine erişim.
     */
    protected function gate(): GateInterface
    {
        return $this->services()->gate;
    }

    /**
     * Aktif kullanıcının verilen yetkisi var mı? (controller içi ince kontrol)
     */
    protected function can(string $permission): bool
    {
        return $this->gate()->allows($permission);
    }

    /**
     * Yetki yoksa ForbiddenException fırlatır.
     *
     * @throws \System\Exceptions\ForbiddenException
     */
    protected function authorize(string $permission): void
    {
        $this->gate()->authorize($permission);
    }

    /**
     * Şablonu render eder ve SONUCU DÖNDÜRÜR.
     *
     * ─────────────────────────────────────────────────────────────────────
     * `$return` PARAMETRESİ VE `exit` KALDIRILDI.
     *
     * Metot eskiden iki modluydu: `$return = true` string döndürüyor,
     * `false` (varsayılan) ise `echo ... ; exit;` yapıyordu. İkinci mod
     * hiç kullanılmıyordu — kod tabanındaki tek çağıran `MonitorController`
     * ve o zaten `$return: true` geçip sonucu `htmlResponse()`'a veriyordu.
     *
     * Yani `exit` eden dal ÖLÜ KODDU ama `Response`'tan `exit` kaldırılırken
     * canlanma riski taşıyordu: yanlışlıkla `$return` verilmeyen bir çağrı,
     * yanıtı yarıda kesip `Content-Length` tutarsızlığı üretirdi.
     *
     * Artık tek mod var: render et, döndür. Çıktıyı kim yayınlayacaksa
     * (`Response::htmlResponse`) onun işi.
     * ─────────────────────────────────────────────────────────────────────
     *
     * @throws BadRequestException Path traversal veya geçersiz path durumunda
     */
    public function view(string $path, string $file, array $data = []): string
    {
        $services = $this->services();

        $data['APP_URL'] = $services->app->url;
        // Path validation (whitelist kontrolü)
        $path = $this->validateViewPath($path);

        // File traversal koruması
        $file = $this->sanitizeViewFile($file);

        $viewPath = $services->app->path($path, 'views');

        // Modül başına Twig namespace: farklı modüllerin aynı-adlı şablonları
        // (@api/index vs @admin/index) çakışmaz, path yığını birikmez.
        // Kayıt artık static TwigFactory::addPath() değil, enjekte edilmiş
        // ViewPathRegistry üzerinden (süreç geneli mutable durum yok).
        $services->viewPaths->add($viewPath, $path);

        $twig = $this->twig();
        $template = '@' . $path . '/' . $file . '.twig';

        return $twig->render($template, $data);
    }

    /**
     * View path'ini doğrula (güvenli format — whitelist YOK).
     *
     * @throws BadRequestException
     */
    private function validateViewPath(string $path): string
    {
        $path = trim($path, '/');

        // Tek-segment güvenli modül adı zorunlu (slash/nokta/backslash/null yok →
        // path traversal imkânsız). Modül listesi sınırlı DEĞİL.
        if (!preg_match(self::MODULE_PATTERN, $path)) {
            throw new BadRequestException(
                'Geçersiz view path',
                ['path' => $path]
            );
        }

        return $path;
    }

    /**
     * View dosya adını sanitize et (path traversal koruması)
     */
    private function sanitizeViewFile(string $file): string
    {
        // Null byte ve traversal karakterlerini temizle
        $file = str_replace(["\0", '..'], '', $file);

        // Backslash'leri forward slash'e çevir
        $file = str_replace('\\', '/', $file);

        // Başlangıç ve bitiş slash'lerini temizle
        $file = trim($file, '/');

        // Uzantı varsa kaldır (sonra .twig eklenecek)
        if (str_ends_with($file, '.twig')) {
            $file = substr($file, 0, -5);
        }

        return $file;
    }
}
