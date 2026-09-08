<?php

declare(strict_types=1);

namespace System\Container\Provider;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use System\Container\Contract\ContainerBuilderInterface;
use System\Container\Lifetime\Lifetime;
use System\Engine\TwigFactory;
use System\Engine\ViewPathRegistry;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use System\Runtime\Sapi;

/**
 * Şablon katmanı (Twig).
 */
final class ViewProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // FilesystemLoader parametresiz kurulabilir; yollar sonradan eklenir.
        $builder->singleton(FilesystemLoader::class);

        // Yol kaydı SINGLETON: modüllerin eklediği şablon yolları worker
        // ömrü boyunca paylaşılır. Bu, eski static `TwigFactory::addPath()`
        // davranışıyla aynı ama artık container'ın bildiği bir servis.
        $builder->singleton(ViewPathRegistry::class);

        $builder->singleton(TwigFactory::class);

        $builder->factory(
            Environment::class,
            [self::class, 'twig'],
            Lifetime::SINGLETON,
            dependsOn: [TwigFactory::class],
        );
    }

    public function provides(): array
    {
        return [
            FilesystemLoader::class,
            ViewPathRegistry::class,
            TwigFactory::class,
            Environment::class,
        ];
    }

    /**
     * Twig yalnızca CLI'da gerekmez ama `cache:warm` gibi komutlar şablon
     * derleyebildiği için CLI'da da kayıtlı bırakılır — lazy olduğu için
     * kullanılmadığı sürece kurulmaz (DI-plan §14).
     */
    public function supports(Sapi $sapi): bool
    {
        return true;
    }

    // ── Factory ────────────────────────────────────────────────────

    public static function twig(PsrContainerInterface $container): Environment
    {
        return $container->get(TwigFactory::class)->create();
    }
}
