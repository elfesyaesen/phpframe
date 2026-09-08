<?php

declare(strict_types=1);

namespace System\Engine;

class Autoloader
{
    /**
     * Namespace öneki => APP_ROOT'a göre taban dizin (olduğu gibi, küçültülmez).
     *
     * ŞU AN BOŞ ve bu doğru durumdur. Üçüncü parti kodun TAMAMI Composer ile
     * `vendor/` altından gelir ve Composer'ın kendi PSR-4 loader'ı tarafından
     * yüklenir (bkz. config/config.php). Bu autoloader yalnızca FIRST-PARTY
     * içindir: `System\`, `Api\`, `Monitor\`.
     *
     * Eskiden burada `'Psr\\' => 'system/library/psr/'` girdisi vardı; elle
     * vendor edilmiş psr/container kopyası namespace'ini koruduğu için genel
     * kural (namespace -> küçük harfli dizin) ona uymuyordu. O kopya
     * `composer require psr/container` ile değiştirildiğinden girdi gereksiz
     * kaldı.
     *
     * Map, upstream namespace'ini koruyan bir dosyayı yine de APP_ROOT altında
     * tutmak gerekirse diye MEKANİZMA olarak korunuyor. Yeni girdi eklenirse
     * uzunluğa göre AZALAN sırada tutun (en uzun önek kazanır); aksi halde iç
     * içe önekler yanlış eşleşir.
     *
     * @var array<string, string>
     */
    private const PREFIXES = [];

    public function __construct()
    {
        spl_autoload_register(function (string $class): void {
            $this->load($class);
        });
    }

    public function load(string $class): bool
    {
        foreach (self::PREFIXES as $prefix => $base) {
            if (str_starts_with($class, $prefix)) {
                return $this->requireFile(
                    $this->prefixedPath(substr($class, strlen($prefix)), $base)
                );
            }
        }

        $parts = explode('\\', $class);

        $className = array_pop($parts);

        $namespacePath = strtolower(implode('/', $parts));

        return $this->requireFile(
            APP_ROOT . DIRECTORY_SEPARATOR . $namespacePath . DIRECTORY_SEPARATOR . $className . '.php'
        );
    }

    /**
     * Önek soyulduktan sonra kalan namespace kuyruğunu dosya yoluna çevirir.
     * Ara segmentler küçültülür (genel kuralla aynı), sınıf adı korunur.
     */
    private function prefixedPath(string $relativeClass, string $base): string
    {
        $parts = explode('\\', $relativeClass);
        $className = array_pop($parts);

        $dir = $parts === [] ? '' : strtolower(implode('/', $parts)) . '/';

        return APP_ROOT . '/' . $base . $dir . $className . '.php';
    }

    private function requireFile(string $filePath): bool
    {
        if (is_file($filePath)) {
            require_once $filePath;
            return true;
        }

        return false;
    }
}