<?php

declare(strict_types=1);

namespace System\Monitor\Contracts;

use System\Monitor\RequestTrace;

/**
 * İsteğin BİR yönünü ize yazan tek-sorumluluklu birim (SRP).
 *
 * Kaynak projede tüm toplama mantığı tek bir `Monitor::capture()` metodunda
 * iç içeydi; yeni bir sinyal eklemek o metodu büyütmek anlamına geliyordu.
 * Burada her sinyal ayrı bir collector'dır: yeni sinyal = yeni sınıf, mevcut
 * kod değişmez (OCP).
 *
 * `collect()` shutdown sırasında, tüm sinyaller (status, süre, exception)
 * biliniyorken çağrılır. Bir collector'ın patlaması diğerlerini durdurmaz —
 * Recorder her çağrıyı tek tek sarar.
 */
interface CollectorInterface
{
    /**
     * Log/teşhis amaçlı kısa ad: 'http' | 'exception' | 'query' | 'cache'.
     */
    public function name(): string;

    public function collect(RequestTrace $trace): void;
}
