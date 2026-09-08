<?php

declare(strict_types=1);

namespace System\Container\Contract;

use System\Container\Compilation\CodeWriter;
use System\Container\Definition\Definition;

/**
 * Kendi derlenmiş kodunu üreten factory — egzotik durumlar için kaçış kapısı.
 *
 * Normal factory'ler (static metot / invokable) derlenmiş container'da bir
 * çağrıya dönüşür; gövdesi runtime'da çalışır. Bazı durumlarda gövdenin
 * TAMAMEN derleme zamanında çözülüp inline edilmesi istenir. O zaman factory
 * kendi ifadesini üretir.
 *
 * Bu, codegen'i compiler'dan çıkarıp KULLANICI kodunda tutar: compiler her
 * özel durumu bilmek zorunda kalmaz.
 */
interface CompilableFactoryInterface
{
    /**
     * Bu servisi kuran PHP İFADESİNİ döndürür (deyim değil, ifade — sonunda
     * noktalı virgül yok).
     *
     * Bağımlılıklara erişim için $writer->serviceCall($id) kullanılır; bu,
     * hedef servisin derlenmiş metoduna doğru çağrıyı üretir.
     *
     * Örnek dönüş:
     *   'new \Redis\Client(' . $writer->serviceCall(RedisConfig::class) . ')'
     */
    public static function compile(CodeWriter $writer, Definition $definition): string;
}
