<?php

declare(strict_types=1);

namespace System\Container\Definition;

/**
 * Bir constructor argümanının nasıl elde edileceği.
 *
 * Bu enum, hem dev runtime'ın hem code generator'ın üzerinde `match` yaptığı
 * TEK ortak dildir. İki yol aynı kolları işlediği için derlenmiş ve
 * derlenmemiş davranış yapısal olarak aynı kalır — "dev'de çalışıyordu,
 * production'da farklı davrandı" sınıfı hatalar bu sayede imkânsızlaşır.
 *
 * YENİ KOL EKLERKEN İKİ YERİ DE GÜNCELLE:
 *   • System\Container\Core\Container::resolveArgument()
 *   • System\Container\Compilation\CompiledContainerGenerator::argument()
 * Biri güncellenip diğeri atlanırsa `match` exhaustive olmadığı için PHP
 * `UnhandledMatchError` fırlatır — yani sessiz ayrışma değil gürültülü hata.
 */
enum ArgumentKind
{
    /** Başka bir servis — container'dan çözülür. */
    case SERVICE;

    /** var_export güvenli sabit değer — derlenmiş koda gömülür. */
    case LITERAL;

    /** instance() ile verilen canlı obje — container kurulurken dışarıdan gelir. */
    case EXTERNAL;

    /** Container'ın kendisi (RuleFactory gibi meşru locator'lar için). */
    case CONTAINER;

    /**
     * Bir etiketle işaretlenmiş TÜM servislerin listesi (DI-plan §20).
     *
     * Örnek: `http.middleware` etiketli her şey bir array olarak enjekte
     * edilir. Derlenmiş kodda bu, sabit bir dizi ifadesine dönüşür —
     * runtime'da etiket sorgusu YOKTUR.
     */
    case TAGGED;

    /**
     * Dekore edilen servisin İÇ katmanı (DI-plan §21).
     *
     * Bir dekoratör kurulurken, sardığı servisin kendisine değil onun
     * ALTINDAKİ katmana ihtiyaç duyar; aksi halde kendini sarar ve sonsuz
     * özyineleme olur.
     */
    case DECORATED;
}
