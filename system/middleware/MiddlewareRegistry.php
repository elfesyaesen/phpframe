<?php

declare(strict_types=1);

namespace System\Middleware;

use Psr\Container\ContainerInterface;
use System\Container\Contract\ContainerInterface as FrameContainerInterface;
use System\Exceptions\InternalServerException;
use System\Middleware\Interface\MiddlewareInterface;

/**
 * Middleware alias → sınıf eşlemesi — SINGLETON.
 *
 * `Alias` sınıfının static `$middlewareMap`'inin yerine geçer. Static olması
 * üç sorun üretiyordu:
 *
 *   1. `Alias::register()` runtime'da eşleme değiştirmeye izin veriyordu —
 *      DI-plan §24'ün (production immutability) açık bir deliği.
 *   2. Eşleme derleme zamanında GÖRÜNMÜYORDU: `monitor_token` alias'ının
 *      işaret ettiği sınıf adı yanlış yazılmışsa hata, o korumalı route'a
 *      ilk istek geldiğinde ortaya çıkıyordu — yani route sessizce
 *      korumasız kalabiliyordu.
 *   3. Test edilemezdi (süreç geneli durum).
 *
 * Artık eşleme `config/middleware.php`'den gelir ve derleyici her hedef
 * sınıfın çözülebilir bir `MiddlewareInterface` olduğunu doğrulayabilir.
 */
final class MiddlewareRegistry
{
    /**
     * @param array<string, class-string<MiddlewareInterface>> $map alias => sınıf
     */
    public function __construct(
        private readonly array $map,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * `alias` veya `alias:parametre` biçimini ayrıştırır.
     *
     * SAF ve static kalır — durumu yoktur, dolayısıyla enjekte edilmesi
     * gereken bir şey değil.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function parse(string $definition): array
    {
        /** @var array{0: string, 1: string|null} $parts */
        $parts = array_pad(explode(':', $definition, 2), 2, null);

        return $parts;
    }

    public function has(string $alias): bool
    {
        return isset($this->map[$alias]);
    }

    /**
     * Alias'ı çözülmüş middleware örneğine çevirir.
     */
    public function resolve(string $alias): MiddlewareInterface
    {
        $class = $this->map[$alias] ?? null;

        if ($class === null) {
            throw new InternalServerException(
                message: "Tanımsız middleware alias'ı: '{$alias}'",
                context: [
                    'alias' => $alias,
                    'available' => array_keys($this->map),
                    'hint' => 'config/middleware.php dosyasına ekleyin.',
                ]
            );
        }

        $middleware = $this->container->get($class);

        if (!$middleware instanceof MiddlewareInterface) {
            throw new InternalServerException(
                message: "Middleware MiddlewareInterface uygulamıyor: '{$class}'",
                context: ['alias' => $alias, 'class' => $class]
            );
        }

        return $middleware;
    }

    /**
     * Kayıtlı alias'lar (teşhis ve derleme kök kümesi için).
     *
     * @return array<string, class-string<MiddlewareInterface>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
