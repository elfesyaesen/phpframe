<?php

declare(strict_types=1);

namespace System\Container\Provider;

use System\Container\Contract\ContainerBuilderInterface;
use System\Http\Request;
use System\Http\Response;

/**
 * HTTP istek/yanıt katmanı.
 *
 * REQUEST VE RESPONSE SCOPED — bu göçün en önemli semantik düzeltmesi:
 *
 * `Request` mutable auth durumu taşır (`userUuid`, `authUser`; AuthMiddleware
 * yazar, controller/Gate/HttpCollector okur). PHP-FPM'de singleton = worker
 * ömrü boyunca tek örnek, yani bir worker'ın gördüğü 2. istek 1. isteğin
 * kimliğini görürdü. Dev'de worker tek istek gördüğü için bu ASLA görünmez;
 * production'da kullanıcı verisi karışır.
 *
 * Eski bootstrap'ta Request'in "monitor bloğundan ÖNCE" kaydedilmesi gereken
 * bir sıra kısıtı vardı (aksi halde monitor container'dan Request isterken
 * binding henüz yok sayılır ve reflection ile İKİNCİ bir örnek üretilirdi,
 * userUuid sonsuza dek null kalırdı). O kısıt burada YOK: iki fazlı provider
 * modelinde tüm register() çağrıları herhangi bir get()'ten önce tamamlanır,
 * dolayısıyla "binding henüz kaydedilmemişti" durumu temsil edilemez.
 */
final class HttpProvider extends AbstractServiceProvider
{
    public function register(ContainerBuilderInterface $builder): void
    {
        // Constructor'ı parametresiz ($_SERVER/$_FILES/php://input okur),
        // dolayısıyla autowire yeterli — factory gerekmez.
        $builder->scoped(Request::class);

        // Response da scoped: yanıt durumu (başlıklar, gönderilmiş mi)
        // per-request. Eski `setContainer($c)` çağrısı KALDIRILDI —
        // Content-Language için gereken LocaleResolver artık constructor'dan
        // gelir (bkz. LocalizationProvider), yani service locator yok.
        $builder->scoped(Response::class);
    }

    public function provides(): array
    {
        return [Request::class, Response::class];
    }
}
