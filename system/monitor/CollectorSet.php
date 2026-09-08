<?php

declare(strict_types=1);

namespace System\Monitor;

use System\Container\Attribute\Tagged;
use System\Monitor\Contracts\CollectorInterface;

/**
 * `monitor.collector` etiketli collector'ların kümesi — SCOPED.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN AYRI BİR SINIF (doğrudan Recorder'a enjekte etmek yerine):
 *
 * `Recorder` primitive parametreler alıyor (`bool $recording`,
 * `string $requestId`, `int $maxQueries`), dolayısıyla autowire edilemez ve
 * bir factory ile kurulmak zorunda. Factory gövdeleri ise derleyiciye
 * OPAKTIR: içindeki `$container->get()` çağrıları grafe girmez.
 *
 * Collector listesi eskiden o factory'nin İÇİNDE inline kuruluyordu:
 *
 *     $collectors[] = $container->get(HttpCollector::class);
 *     $collectors[] = $container->get(FatalErrorCollector::class);
 *
 * Üç sonucu vardı:
 *   1. Yeni bir collector eklemek factory'yi düzenlemek demekti — oysa
 *      `CollectorInterface`'in tüm tasarım amacı OCP'ydi ("yeni sinyal =
 *      yeni sınıf, mevcut kod değişmez").
 *   2. Collector'ların bağımlılıkları GRAFE GİRMİYORDU: bir collector
 *      scoped state yakalasa scope doğrulaması bunu göremezdi.
 *   3. Üçüncü taraf bir modül collector ekleyemiyordu.
 *
 * Etiket + bu ince kap üçünü de çözer: liste bildirimsel olur, kenarlar
 * grafe girer, ve `$b->tag([...], 'monitor.collector')` ile herkes ekleyebilir.
 * ─────────────────────────────────────────────────────────────────────────
 */
final readonly class CollectorSet
{
    /**
     * @param list<CollectorInterface> $collectors
     */
    public function __construct(
        #[Tagged(self::TAG)] public array $collectors,
    ) {}

    public const TAG = 'monitor.collector';

    /**
     * Kayıt kapalıysa boş küme — Recorder'ın collector'ları hiç çalıştırmaması
     * gerektiği durum.
     *
     * @return list<CollectorInterface>
     */
    public function all(): array
    {
        return $this->collectors;
    }

    /**
     * Teşhis: hangi collector'lar aktif.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static fn(CollectorInterface $collector): string => $collector->name(),
            $this->collectors,
        );
    }
}
