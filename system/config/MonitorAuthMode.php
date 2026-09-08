<?php

declare(strict_types=1);

namespace System\Config;

/**
 * Monitor dashboard erişim modu.
 *
 * Eskiden `MONITOR_AUTH_MODE` bir string sabitti ve `config.php` içinde
 * `in_array(..., ['rbac','token','both'])` ile doğrulanıyordu. Enum o
 * doğrulamayı gereksiz kılar: geçersiz bir değer artık temsil edilemez.
 */
enum MonitorAuthMode: string
{
    /** Oturum + administrator rolü (varsayılan). */
    case RBAC = 'rbac';

    /** Yalnızca paylaşılan sır — oturumsuz operatör erişimi. */
    case TOKEN = 'token';

    /** İkisi birlikte. */
    case BOTH = 'both';

    public static function fromEnvValue(string $value): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? self::RBAC;
    }

    /** Bu mod paylaşılan sır kullanıyor mu (güçlü token zorunluluğu)? */
    public function usesToken(): bool
    {
        return $this === self::TOKEN || $this === self::BOTH;
    }

    public function usesRbac(): bool
    {
        return $this === self::RBAC || $this === self::BOTH;
    }
}
