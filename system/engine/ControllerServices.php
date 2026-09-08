<?php

declare(strict_types=1);

namespace System\Engine;

use System\Config\AppConfig;
use System\Http\Request;
use System\Http\Response;
use Twig\Environment;
use System\Security\Contract\GateInterface;
use System\Translation\Contract\TranslatorInterface;

/**
 * Controller'ların ihtiyaç duyduğu servis kümesi — SCOPED.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN VAR:
 *
 * `BaseController` container'ı private tutup yalnızca 5 daraltılmış accessor
 * (`request()`, `response()`, `twig()`, `translator()`, `gate()`) sunuyordu.
 * SEZGİ DOĞRUYDU — keyfi servis çekmeyi yapısal olarak engellemek — ama
 * MEKANİZMA yanlıştı: accessor'lar runtime `$container->get()` çağrısıydı,
 * dolayısıyla
 *
 *   • controller'ın GERÇEK bağımlılıkları imzasında görünmüyordu,
 *   • `container:debug SomeController` sıfır bağımlılık gösteriyordu,
 *   • scope doğrulaması (DI-plan §19) bir controller'ın scoped state
 *     yakalayıp yakalamadığını denetleyemiyordu,
 *   • ve her erişim bir map lookup'ı ödüyordu.
 *
 * Bu obje aynı daraltılmış yüzeyi korur ama bağımlılıkları BİLDİRİR: derleyici
 * onları görür, doğrular ve derlenmiş container'da doğrudan çağrılara çevirir.
 * Controller başına 5 lazy lookup yerine istek başına bir kurulum.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * SCOPED olmak zorunda: Request, Response, Translator ve Gate scoped.
 */
final readonly class ControllerServices
{
    public function __construct(
        public Request $request,
        public Response $response,
        public Environment $twig,
        public TranslatorInterface $translator,
        public GateInterface $gate,
        public ViewPathRegistry $viewPaths,
        public AppConfig $app,
    ) {}
}
