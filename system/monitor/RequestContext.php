<?php

declare(strict_types=1);

namespace System\Monitor;

/**
 * İsteğin ölçüm başlangıç durumu.
 *
 * SCOPED bir servistir ve `System\Runtime\RequestSnapshot`'tan beslenir
 * (bkz. MonitorProvider); `HttpCollector` shutdown'da buradan okur.
 *
 * Eskiden `MonitorBootstrapper::boot()` tarafından bir boot metodunun
 * İÇİNDEN imperative olarak `$container->singleton(...)` ile kaydediliyordu.
 * Bu hem derlenemezdi (tanım runtime'da oluşuyordu) hem de singleton olduğu
 * için bir PHP-FPM worker'ının gördüğü tüm isteklerin ölçümü ilk isteğin
 * başlangıç zamanına göre yapılıyordu.
 *
 * İki şeyi TAŞIMAK ZORUNDA olmasının nedenleri:
 *
 * 1. `$rawInput`: `php://input` yalnızca BİR KEZ okunabilir. `Request::json()`
 *    stream'i tüketir; monitor shutdown'da okumaya çalışsa boş string alır.
 *    Bu yüzden gövde istek scope'u açılırken, router çalışmadan önce
 *    yakalanır (bkz. Kernel::beginRequestScope — tüm boot'taki tek haklı
 *    eager çözümleme).
 * 2. `$startedAt`: `REQUEST_TIME_FLOAT` PHP-FPM'in isteği ALDIĞI andır;
 *    `microtime()` ise bootstrap'ın çalıştığı an. İlki gerçek kullanıcı
 *    gecikmesini ölçer, ikincisi autoload/config süresini kaçırır.
 */
final class RequestContext
{
    public function __construct(
        private readonly float $startedAt,
        private readonly string $rawInput,
        private readonly bool $bufferingResponse,
    ) {}

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    public function rawInput(): string
    {
        return $this->rawInput;
    }

    /**
     * O ana kadar üretilmiş yanıt gövdesi.
     *
     * `ob_get_contents()` kullanılır, `ob_get_clean()` DEĞİL: buffer'ı
     * boşaltmak istemciye giden yanıtı yutar. Buffer'ı kapatmak PHP'nin
     * kendi shutdown akışına bırakılır.
     */
    public function responseBody(): string
    {
        if (!$this->bufferingResponse) {
            return '';
        }

        $contents = ob_get_contents();

        return $contents === false ? '' : $contents;
    }
}
