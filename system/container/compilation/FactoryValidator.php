<?php

declare(strict_types=1);

namespace System\Container\Compilation;

use System\Container\Contract\CompilableFactoryInterface;
use System\Container\Definition\Definition;
use System\Container\Definition\FactoryKind;

/**
 * Factory tanımlarını derlenebilirlik açısından doğrular (DI-plan §13).
 *
 * Üç iş yapar:
 *   1. Closure factory'leri HATA olarak raporlar, static metoda dönüştürme
 *      yolunu tam kaynak satırıyla gösterir. bootstrap.php'deki closure'ların
 *      göç rehberi bu mesajdır.
 *   2. Static/invokable/compilable hedeflerin gerçekten çağrılabilir olduğunu
 *      doğrular (sınıf silinmiş, metot yeniden adlandırılmış olabilir).
 *   3. Bağımlılıklarını bildirmemiş factory'leri BİLGİ olarak raporlar.
 *      Opaklık meşrudur (döngü kırma) ama sessiz kalmamalı: bildirilmemiş
 *      bağımlılık döngü ve scope doğrulamasına GİRMEZ, yani o factory
 *      içindeki bir singleton→scoped ihlali compiler tarafından görülemez.
 */
final class FactoryValidator
{
    /**
     * @param array<string, Definition> $definitions
     * @return list<Diagnostic>
     */
    public function validate(array $definitions): array
    {
        $diagnostics = [];

        foreach ($definitions as $id => $definition) {
            $factory = $definition->factory;
            if ($factory === null) {
                continue;
            }

            if ($factory->kind === FactoryKind::CLOSURE) {
                $diagnostics[] = Diagnostic::error(
                    'FACTORY_NOT_COMPILABLE',
                    'Closure factory derlenemez.',
                    [$id, 'tanım yeri: ' . ($factory->definedAt ?? 'bilinmiyor')],
                    'closure',
                    "Bir Closure'ın temsil edilebilir kaynak biçimi yoktur;\n"
                    . "var_export() onu basamaz, gövdesi geri alınamaz.\n"
                    . "\n"
                    . "Çözüm — static provider metoduna dönüştür (gövde birebir taşınır):\n"
                    . "\n"
                    . "  \$b->factory({$id}::class, [SomeProvider::class, 'create']);\n"
                    . "\n"
                    . "  public static function create(ContainerInterface \$c): mixed\n"
                    . "  {\n"
                    . "      // eski closure gövdesi buraya, değiştirmeden\n"
                    . "  }",
                    $id,
                    $definition->source,
                );

                continue;
            }

            $diagnostics = [...$diagnostics, ...$this->validateTarget($id, $definition)];

            if ($definition->dependsOn === []) {
                $diagnostics[] = Diagnostic::notice(
                    'FACTORY_UNDECLARED_DEPS',
                    'Factory bağımlılıklarını bildirmemiş.',
                    [$id . '  ' . $factory->describe()],
                    'graf-opak',
                    "Factory gövdesindeki \$c->get() çağrıları derleyiciye GÖRÜNMEZ.\n"
                    . "Sonuç: bu factory'nin bağımlılıkları döngü ve scope\n"
                    . "doğrulamasına girmez — içindeki bir singleton→scoped ihlali\n"
                    . "compile-time'da yakalanamaz.\n"
                    . "Kasıtlı olabilir (döngü kırma); o zaman bu bilgi yeterlidir.\n"
                    . "Değilse bildir:\n"
                    . "  \$b->factory({$id}::class, [P::class, 'x'], dependsOn: [Dep::class]);",
                    $id,
                    $definition->source,
                );
            }
        }

        return $diagnostics;
    }

    /**
     * @return list<Diagnostic>
     */
    private function validateTarget(string $id, Definition $definition): array
    {
        $factory = $definition->factory;
        if ($factory === null || $factory->class === null) {
            return [];
        }

        $class = $factory->class;

        if (!class_exists($class)) {
            return [Diagnostic::error(
                'FACTORY_CLASS_MISSING',
                "Factory sınıfı bulunamadı: '{$class}'",
                [$id, $factory->describe()],
                'sınıf yok',
                "Sınıf silinmiş veya yeniden adlandırılmış olabilir.\n"
                . "Autoloader namespace'i küçük harfli dizin yoluna çevirir —\n"
                . "dizin adlarının küçük harf olduğunu doğrula.",
                $id,
                $definition->source,
            )];
        }

        return match ($factory->kind) {
            FactoryKind::STATIC_METHOD => $this->validateStatic($id, $definition, $class, (string) $factory->method),
            FactoryKind::INVOKABLE     => $this->validateInvokable($id, $definition, $class),
            FactoryKind::COMPILABLE    => $this->validateCompilable($id, $definition, $class),
            FactoryKind::CLOSURE       => [],
        };
    }

    /** @return list<Diagnostic> */
    private function validateStatic(string $id, Definition $definition, string $class, string $method): array
    {
        if (!method_exists($class, $method)) {
            return [Diagnostic::error(
                'FACTORY_METHOD_MISSING',
                "Factory metodu yok: {$class}::{$method}()",
                [$id],
                'metot yok',
                'Metot yeniden adlandırılmış olabilir.',
                $id,
                $definition->source,
            )];
        }

        $reflection = new \ReflectionMethod($class, $method);

        if (!$reflection->isStatic()) {
            return [Diagnostic::error(
                'FACTORY_NOT_STATIC',
                "Factory metodu static değil: {$class}::{$method}()",
                [$id],
                'instance gerektirir',
                "Çözüm: metodu static yap, ya da sınıfı invokable yapıp\n"
                . "sınıf adını ver (o zaman factory de derlenmiş bir servis olur).",
                $id,
                $definition->source,
            )];
        }

        if (!$reflection->isPublic()) {
            return [Diagnostic::error(
                'FACTORY_NOT_PUBLIC',
                "Factory metodu public değil: {$class}::{$method}()",
                [$id],
                'erişilemez',
                'Derlenmiş container bu metodu dışarıdan çağırır; public olmak zorunda.',
                $id,
                $definition->source,
            )];
        }

        return [];
    }

    /** @return list<Diagnostic> */
    private function validateInvokable(string $id, Definition $definition, string $class): array
    {
        if (!method_exists($class, '__invoke')) {
            return [Diagnostic::error(
                'FACTORY_NOT_INVOKABLE',
                "'{$class}' __invoke() sunmuyor.",
                [$id],
                'invokable değil',
                'Çözüm: __invoke() ekle, ya da [Sınıf::class, \'metot\'] çifti ver.',
                $id,
                $definition->source,
            )];
        }

        return [];
    }

    /** @return list<Diagnostic> */
    private function validateCompilable(string $id, Definition $definition, string $class): array
    {
        if (!is_subclass_of($class, CompilableFactoryInterface::class)) {
            return [Diagnostic::error(
                'FACTORY_NOT_COMPILABLE_IFACE',
                "'{$class}' CompilableFactoryInterface uygulamıyor.",
                [$id],
                'arayüz eksik',
                'Tanım COMPILABLE olarak kaydedilmiş ama sınıf arayüzü kaybetmiş.',
                $id,
                $definition->source,
            )];
        }

        return [];
    }
}
