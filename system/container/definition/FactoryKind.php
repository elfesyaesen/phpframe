<?php

declare(strict_types=1);

namespace System\Container\Definition;

/**
 * Factory'nin nasıl çağrılacağı — ve derlenebilir olup olmadığı.
 *
 * Derlenebilirliğin tek ölçütü şudur: factory'ye bir REFERANS yazılabiliyor mu?
 * Static metot adı yazılabilir, sınıf adı yazılabilir; bir Closure'a referans
 * yazılamaz (kaynağı geri alınamaz, serialize edilemez, var_export edilemez).
 */
enum FactoryKind: string
{
    /** [Sınıf::class, 'metot'] → derlenmiş kodda `\Sınıf::metot($this)` */
    case STATIC_METHOD = 'static';

    /**
     * __invoke'lu sınıf. Factory'nin KENDİSİ de derlenmiş bir servis olur,
     * yani onun bağımlılıkları da doğrulanır ve enjekte edilir.
     */
    case INVOKABLE = 'invokable';

    /** CompilableFactoryInterface — kendi ifadesini üretir, inline edilir. */
    case COMPILABLE = 'compilable';

    /** Closure — YALNIZCA dev. Derlemede hata verir. */
    case CLOSURE = 'closure';

    public function isCompilable(): bool
    {
        return $this !== self::CLOSURE;
    }
}
