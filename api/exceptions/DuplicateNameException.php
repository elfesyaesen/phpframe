<?php

declare(strict_types=1);

namespace Api\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Benzersiz olması gereken bir ad zaten kullanımda.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * NEDEN AYRI BİR İSTİSNA — ve neden `System\Exceptions\*` DEĞİL:
 *
 * Model katmanı HTTP bilmez (bkz. docs/api-layer.md). Bir `ConflictException`
 * ya da `ValidationException` fırlatmak, durum kodu ve çevrilmiş mesaj
 * kararını persistence'a taşırdı. Model yalnızca OLGUYU bildirir — "bu ad
 * zaten var" — HTTP karşılığını servis seçer.
 *
 * Yerini aldığı desen: modeller `string|array{error:string}` döndürüyordu ve
 * çağıranlar `is_array($result)` ile hata ayırt ediyordu. Bu union-dönüş
 * kod tabanında 6 çağrı noktasına yayılmıştı; her yeni çağıran kuralı
 * yeniden hatırlamak zorundaydı ve unutmak sessizce "başarı" demekti.
 * ─────────────────────────────────────────────────────────────────────────
 */
final class DuplicateNameException extends RuntimeException
{
    public function __construct(
        /** Çakışan değer — servis, çevrilmiş mesajı bununla kurabilir. */
        public readonly string $name,
        /**
         * Çakışmanın hangi ALANDA olduğu.
         *
         * Varsayılan `name`, rol/yetki gibi tek benzersiz alanı olan kayıtlar
         * içindir. `user` tablosunda İKİ ayrı benzersiz alan var (`email` ve
         * `username`) ve istemciye hangisinin çakıştığı bildirilmeli — aksi
         * halde kullanıcı hangi alanı düzelteceğini bilemez.
         */
        public readonly string $field = 'name',
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('%s zaten kullanımda: %s', $field, $name), 0, $previous);
    }
}
