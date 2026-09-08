<?php

declare(strict_types=1);

namespace System\Engine;

use Twig\Loader\FilesystemLoader;

/**
 * Twig şablon yollarının kaydı — SINGLETON.
 *
 * `TwigFactory::addPath()` static metodunun yerine geçer. Static olması iki
 * sorun üretiyordu:
 *
 *   1. Süreç geneli mutable durum: bir modülün eklediği yol, aynı worker'ın
 *      gördüğü sonraki isteklerde de kayıtlı kalıyordu. Genelde zararsız ama
 *      "hangi yollar kayıtlı" sorusu isteğe göre değişmez olmalıyken
 *      olmuyordu.
 *   2. Test edilemez ve enjekte edilemez: `BaseController::view()` doğrudan
 *      static çağırıyordu, yani bağımlılık imzada görünmüyordu.
 *
 * Dedupe davranışı birebir korunur (`in_array(..., true)`): aynı yol iki kez
 * eklenirse Twig'e bir kez bildirilir.
 */
final class ViewPathRegistry
{
    /** @var array<string, list<string>> namespace => yollar */
    private array $paths = [];

    public function __construct(
        private readonly FilesystemLoader $loader,
    ) {}

    /**
     * Şablon yolu ekler. Aynı (yol, namespace) çifti tekrar eklenirse
     * loader'a ikinci kez bildirilmez.
     */
    public function add(string $path, string $namespace = FilesystemLoader::MAIN_NAMESPACE): void
    {
        if (!is_dir($path)) {
            return;
        }

        $registered = $this->paths[$namespace] ?? [];

        if (in_array($path, $registered, true)) {
            return;
        }

        $this->paths[$namespace][] = $path;
        $this->loader->addPath($path, $namespace);
    }

    public function loader(): FilesystemLoader
    {
        return $this->loader;
    }

    /**
     * Kayıtlı yollar (teşhis için).
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->paths;
    }
}
