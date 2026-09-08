<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Config\AppConfig;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Engine\ControllerServices;
use System\Routing\Router;
use System\Routing\RouterFactory;
use System\Runtime\Sapi;

/**
 * Yönlendirme.
 */
final class RoutingProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        $builder->singleton(RouterFactory::class);

        $builder->factory(
            Router::class,
            [self::class, 'router'],
            Lifetime::SINGLETON,
            dependsOn: [RouterFactory::class],
        );

        // Controller'ların framework servis kümesi — SCOPED (Request,
        // Response, Translator, Gate scoped). Router her controller'a bunu
        // setter ile verir.
        $builder->scoped(ControllerServices::class);
    }

    public function provides(): array
    {
        return [RouterFactory::class, Router::class, ControllerServices::class];
    }

    /**
     * CLI'da da kayıtlı — ama bedeli ödenmiyor.
     *
     * Router kurulumu attribute taraması ve (dev'de) controller dizinlerinin
     * recursive `stat`'lanmasını içerir; her `frame` çağrısında bunu ödemek
     * anlamsız olurdu. Ancak tanımlar LAZY olduğu için (DI-plan §14) Router
     * yalnızca gerçekten istendiğinde kurulur.
     *
     * CLI'da kayıtlı olması ZORUNLU: `cache:warm` ve `optimize` komutları
     * route cache'ini üretmek için gerçek router'a ihtiyaç duyar. Eskiden
     * bunu `require_once bootstrap.php` ile elde ediyorlardı — artık bu,
     * ikinci bir Kernel kurmak (çifte boot, çifte shutdown hook) anlamına
     * geldiği için mümkün değil.
     */
    public function supports(Sapi $sapi): bool
    {
        return true;
    }

    // ── Factory ────────────────────────────────────────────────────

    public static function router(PsrContainerInterface $container): Router
    {
        return $container->get(RouterFactory::class)->create();
    }
}
