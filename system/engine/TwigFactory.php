<?php

declare(strict_types=1);

namespace System\Engine;

use RuntimeException;

use System\Config\AppConfig;
use System\Media\Media;
use System\Media\MediaUrlGenerator;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;

final class TwigFactory
{
    public function __construct(
        private readonly AppConfig $app,
        private readonly ViewPathRegistry $paths,
        private readonly ?MediaUrlGenerator $media = null,
    ) {}

    /**
     * @param bool|null $production
     */
    public function create(?bool $production = null): Environment
    {
        $production ??= $this->app->production;

        $twig = new Environment($this->paths->loader(), [
            'cache' => $production ? $this->app->cachePath('twig') : false,
            'debug' => !$production,
            'charset' => 'UTF-8',
            'strict_variables' => !$production,
            'auto_reload' => !$production,
        ]);

        if (!$production) {
            $twig->addExtension(new DebugExtension());
        }

        $this->addCsrfFunctions($twig);
        $this->addMediaFunctions($twig);

        return $twig;
    }

    private function addMediaFunctions(Environment $twig): void
    {
        $generator = $this->media;

        $resolve = static function (mixed $media): ?Media {
            return match (true) {
                $media instanceof Media                   => $media,
                is_array($media) && isset($media['uuid']) => Media::fromRow($media),
                default                                   => null,
            };
        };

        $require = static function () use ($generator): MediaUrlGenerator {
            return $generator ?? throw new RuntimeException('media_url/media_srcset: MediaUrlGenerator kurulmamış.');
        };

        $twig->addFunction(new TwigFunction(
            'media_url',
            static function (mixed $media, string $variant = Media::FULL_VARIANT, ?string $format = null) use ($resolve, $require): string {
                $resolved = $resolve($media);

                return $resolved === null ? '' : (string) $require()->url($resolved, $variant, $format);
            },
        ));

        $twig->addFunction(new TwigFunction(
            'media_srcset',
            static function (mixed $media, string $format = 'webp') use ($resolve, $require): string {
                $resolved = $resolve($media);

                return $resolved === null ? '' : $require()->srcset($resolved, $format);
            },
        ));
    }

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

        $twig->addFunction(new TwigFunction(
            'csrf_token',
            static fn(): string => $notImplemented('csrf_token'),
        ));

        $twig->addFunction(new TwigFunction(
            'csrf_field',
            static fn(): string => $notImplemented('csrf_field'),
            ['is_safe' => ['html']],
        ));

        $twig->addFunction(new TwigFunction(
            'csrf_meta',
            static fn(): string => $notImplemented('csrf_meta'),
            ['is_safe' => ['html']],
        ));
    }
}
