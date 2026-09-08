<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use System\Monitor\RequestTrace;

/**
 * Örnekleme kararı: bu iz saklanacak mı?
 *
 * Karar VERİ TOPLANDIKTAN SONRA verilir, önce değil. Sebep: "hatalı ve yavaş
 * istekler her zaman saklanır" kuralı ancak status ve süre bilindiğinde
 * uygulanabilir. Toplama maliyeti (bellekte birkaç dizi) zaten ihmal
 * edilebilir; pahalı olan yazma adımıdır ve örnekleme onu hedefler.
 */
interface SamplerInterface
{
    public function shouldStore(RequestTrace $trace): bool;
}
