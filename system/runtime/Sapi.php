<?php

declare(strict_types=1);

namespace System\Runtime;

/**
 * Çalışma ortamı: HTTP isteği mi CLI komutu mu.
 *
 * Bu enum, kod tabanına dağılmış `PHP_SAPI !== 'cli'` kontrollerinin yerini
 * alır. Kontrol tek yerde yapılır, sonuç bildirimsel olarak taşınır:
 * bir provider `supports(Sapi $sapi)` ile hangi ortamda katıldığını söyler,
 * böylece "CLI'da monitor collector kaydetme" gibi kararlar servis
 * gövdelerinden çıkıp tanım katmanına taşınır.
 */
enum Sapi: string
{
    case Http = 'http';
    case Cli  = 'cli';

    public static function detect(): self
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ? self::Cli : self::Http;
    }

    public function isCli(): bool
    {
        return $this === self::Cli;
    }

    public function isHttp(): bool
    {
        return $this === self::Http;
    }
}
