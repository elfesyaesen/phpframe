<?php

declare(strict_types=1);

namespace System\Container\Exception;

final class FactoryException extends ContainerException
{
    public static function closureNotCompilable(string $id, ?string $definedAt): self
    {
        $where = $definedAt ?? 'bilinmiyor';

        return new self(
            self::render(
                'FACTORY_NOT_COMPILABLE',
                'Closure factory derlenemez.',
                [$id, 'tanım yeri: ' . $where],
                'closure',
                "Bir Closure'ın temsil edilebilir kaynak biçimi yoktur; var_export()\n"
                . "onu basamaz, serialize edilemez, gövdesi güvenilir şekilde geri\n"
                . "alınamaz. Bu yüzden closure yalnızca dev'de çalışır.\n"
                . "\n"
                . "Çözüm — static provider metoduna dönüştür (gövde birebir taşınır):\n"
                . "\n"
                . "  final class SomeProvider extends AbstractServiceProvider\n"
                . "  {\n"
                . "      public function register(ContainerBuilderInterface \$b): void\n"
                . "      {\n"
                . "          \$b->factory({$id}::class, [self::class, 'create']);\n"
                . "      }\n"
                . "\n"
                . "      public static function create(ContainerInterface \$c): mixed\n"
                . "      {\n"
                . "          // eski closure gövdesi buraya, değiştirmeden\n"
                . "      }\n"
                . "  }"
            ),
            'FACTORY_NOT_COMPILABLE',
            [$id],
            $id,
        );
    }

    public static function notStatic(string $class, string $method): self
    {
        return new self(
            self::render(
                'FACTORY_NOT_STATIC',
                "Factory metodu static değil: {$class}::{$method}()",
                [$class . '::' . $method . '()'],
                'instance gerektirir',
                "Instance metodu çağırmak için önce o sınıfın örneği gerekir; bu\n"
                . "derlenmiş kodda ek bir çözümleme adımı demektir.\n"
                . "Çözüm 1: metodu `static` yap.\n"
                . "Çözüm 2: sınıfı invokable yap (__invoke) ve sınıf adını ver —\n"
                . "         o zaman factory'nin kendisi de derlenmiş bir servis olur:\n"
                . "           \$b->factory(Foo::class, {$class}::class);"
            ),
            'FACTORY_NOT_STATIC',
            [$class],
            $class,
        );
    }

    public static function notCallable(string $class): self
    {
        return new self(
            self::render(
                'FACTORY_NOT_CALLABLE',
                "'{$class}' bir factory olarak kullanılamaz.",
                [$class],
                'çağrılabilir değil',
                "Factory olarak verilen sınıf şunlardan birini sunmalı:\n"
                . "  • __invoke()                        → invokable factory\n"
                . "  • public static create()            → static factory\n"
                . "  • CompilableFactoryInterface        → kendi kodunu üretir\n"
                . "Çözüm: bunlardan birini ekle, ya da [Sınıf::class, 'metot'] çifti ver."
            ),
            'FACTORY_NOT_CALLABLE',
            [$class],
            $class,
        );
    }

    public static function unsupported(string $id, string $given): self
    {
        return new self(
            self::render(
                'FACTORY_UNSUPPORTED',
                "'{$id}' için desteklenmeyen factory ifadesi: {$given}",
                [$id],
                'geçersiz factory',
                "Kabul edilen biçimler:\n"
                . "  [Sınıf::class, 'staticMetot']   Sınıf::class (invokable/create)   Closure (yalnızca dev)"
            ),
            'FACTORY_UNSUPPORTED',
            [$id],
            $id,
        );
    }

    public static function wrongReturnType(string $id, string $expected, string $actual): self
    {
        return new self(
            self::render(
                'FACTORY_WRONG_TYPE',
                "Factory beklenen tipi döndürmedi.",
                [$id, 'beklenen: ' . $expected, 'dönen: ' . $actual],
                'tip uyuşmazlığı',
                "Çözüm: factory metodunun dönüş tipini bildir; böylece hata\n"
                . "container'da değil, factory'nin kendisinde yakalanır."
            ),
            'FACTORY_WRONG_TYPE',
            [$id],
            $id,
        );
    }
}
