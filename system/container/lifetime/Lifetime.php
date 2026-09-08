<?php

declare(strict_types=1);

namespace System\Container\Lifetime;

/**
 * Bir servisin yaşam süresi (DI-plan §7).
 *
 * PHP-FPM açısından kritik ayrım SINGLETON ile SCOPED arasındadır: bir worker
 * ardışık birçok isteği aynı process'te karşılar, dolayısıyla SINGLETON
 * "process singleton"dır. Mutable per-request state taşıyan servisler
 * (Request, RequestContext, Recorder, auth durumu) SCOPED olmak ZORUNDA;
 * aksi halde 1. isteğin durumu o worker'daki her sonraki isteğe sızar
 * (DI-plan §8).
 */
enum Lifetime: string
{
    /** Her çözümlemede yeni instance. */
    case TRANSIENT = 'transient';

    /** Container ömrü boyunca (= PHP-FPM worker ömrü boyunca) tek instance. */
    case SINGLETON = 'singleton';

    /** Scope (HTTP isteği / CLI komutu) başına tek instance. */
    case SCOPED = 'scoped';

    /** Dışarıdan hazır verilen instance; container oluşturmaz. */
    case INSTANCE = 'instance';

    /**
     * Ömür sıralaması: küçük rank = kısa ömür.
     *
     * ScopeValidator (DI-plan §19) bunu kullanır: uzun ömürlü bir servis kısa
     * ömürlü bir servisi yakalarsa (SINGLETON → SCOPED) kısa ömürlü olan
     * sonsuza dek pinlenir.
     */
    public function rank(): int
    {
        return match ($this) {
            self::TRANSIENT => 0,
            self::SCOPED    => 1,
            self::SINGLETON => 2,
            self::INSTANCE  => 3,
        };
    }

    /** Örnek paylaşılıyor mu (bir store'da saklanıyor mu)? */
    public function isShared(): bool
    {
        return $this !== self::TRANSIENT;
    }

    /** Çözümlemek için aktif bir scope gerekiyor mu? */
    public function requiresScope(): bool
    {
        return $this === self::SCOPED;
    }

    /** Hata mesajlarında ve CLI tablolarında kullanılan etiket. */
    public function label(): string
    {
        return $this->value;
    }
}
