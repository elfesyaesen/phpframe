<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Binding\BindingRegistry;
use System\Container\Definition\DefinitionRegistry;
use System\Engine\ModuleRegistry;

/**
 * Derleme kök kümesini toplar — compiler'ın en zor kararı.
 *
 * NEDEN "HER DOSYAYI TARA" YANLIŞ:
 * Eski ContainerCompiler her modüldeki her PHP dosyasını tarayıp autowire
 * etmeye çalışıyordu ve çözemediğini sessizce `return null` ile atlıyordu.
 * Yeni tasarımda çözülemeyen bağımlılık HATA olduğu için aynı yaklaşım,
 * hiç servis olmayan sınıflarda (primitive constructor'lı modeller, DTO'lar,
 * value object'ler) derlemeyi düşürürdü.
 *
 * Bu yüzden servis kümesi ROOT'LARDAN TÜRETİLİR:
 *   1. Açık tanımlar ve binding'ler (provider'ların yazdığı her şey)
 *   2. Controller'lar — HTTP dispatch container'dan çözer
 *   3. Console komutları — CLI dispatch container'dan çözer
 *   4. Middleware — pipeline container'dan çözer
 *
 * Bunların transitive closure'ı gerçek servis kümesidir. Kümeye girmeyen bir
 * sınıf derlenmez ve bu SESSİZ KALMAZ: `container:list --uncompiled`
 * atlananları sebebiyle listeler, böylece kapsam boşluğu görünür olur.
 */
final class RootCollector
{
    /** Controller/komut aranırken atlanacak dizin adları. */
    private const SKIP_DIRS = ['views', 'cache', 'library'];

    /**
     * @param bool $discover false ise dosya sistemi taraması yapılmaz ve
     *        kök kümesi yalnızca açık tanım/binding'lerden oluşur.
     *        `container:validate --definitions-only` ve izole doğrulama için.
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly ClassScanner $scanner = new ClassScanner(),
        private readonly bool $discover = true,
    ) {}

    /**
     * @param list<string>|null $extraRoots Ek kökler (config/middleware.php vb.)
     * @return array<string, string> id => kök kaynağı (teşhis için)
     */
    public function collect(
        DefinitionRegistry $definitions,
        BindingRegistry $bindings,
        ?array $extraRoots = null,
    ): array {
        $roots = [];

        // 1. Provider'ların yazdığı her şey.
        foreach (array_keys($definitions->sorted()) as $id) {
            $roots[$id] = 'tanım';
        }

        foreach (array_keys($bindings->sorted()) as $id) {
            $roots[$id] ??= 'binding';
        }

        if ($this->discover) {
            // 2-3. Modül controller'ları ve console komutları.
            foreach ($this->controllers() as $class) {
                $roots[$class] ??= 'controller';
            }

            foreach ($this->commands() as $class) {
                $roots[$class] ??= 'komut';
            }

            // 4. Modül middleware'leri.
            foreach ($this->middleware() as $class) {
                $roots[$class] ??= 'middleware';
            }
        }

        foreach ($extraRoots ?? [] as $class) {
            $roots[$class] ??= 'ek kök';
        }

        ksort($roots);

        return $roots;
    }

    /**
     * Modül `controllers/` dizinlerindeki sınıflar.
     *
     * @return list<string>
     */
    public function controllers(): array
    {
        $classes = [];

        foreach (ModuleRegistry::discover($this->appRoot) as $module) {
            $classes = [
                ...$classes,
                ...$this->scanner->scanDirectory(
                    $this->appRoot . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'controllers',
                    self::SKIP_DIRS
                ),
            ];
        }

        return $this->existing($classes);
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return $this->existing($this->scanner->scanDirectory(
            $this->appRoot . '/system/console/commands',
            self::SKIP_DIRS
        ));
    }

    /**
     * Framework ve modül middleware'leri.
     *
     * @return list<string>
     */
    public function middleware(): array
    {
        $dirs = [$this->appRoot . '/system/middleware'];

        foreach (ModuleRegistry::discover($this->appRoot) as $module) {
            $dirs[] = $this->appRoot . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'middleware';
        }

        $classes = [];
        foreach ($dirs as $dir) {
            $classes = [...$classes, ...$this->scanner->scanDirectory($dir, self::SKIP_DIRS)];
        }

        return $this->existing($classes);
    }

    /**
     * Autowire edilebilecek namespace önekleri.
     *
     * System\ her zaman + her modül (ucfirst). Bu türetme eski compiler'dan
     * korunmuştur. Amacı: PDO, Redis, Twig internal'ları gibi framework
     * dışı sınıfların örtük olarak autowire EDİLMEMESİ — onlar açıkça bind
     * edilmek veya factory ile kurulmak zorunda.
     *
     * @return list<string>
     */
    public function allowedPrefixes(): array
    {
        $prefixes = ['System\\'];

        foreach (ModuleRegistry::discover($this->appRoot) as $module) {
            $prefixes[] = ucfirst($module) . '\\';
        }

        return $prefixes;
    }

    public function isAllowed(string $class): bool
    {
        foreach ($this->allowedPrefixes() as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Yalnızca gerçekten yüklenebilen, örneklenebilir sınıfları bırakır.
     *
     * Tarayıcı token analizi yapar, sınıfı yüklemez; abstract sınıflar,
     * arayüzler ve autoload edilemeyen dosyalar burada süzülür.
     *
     * @param list<string> $classes
     * @return list<string>
     */
    private function existing(array $classes): array
    {
        $out = [];

        foreach (array_unique($classes) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                continue;
            }

            $out[] = $class;
        }

        sort($out);

        return $out;
    }
}
