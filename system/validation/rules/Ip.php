<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

/**
 * IP Adresi Validation Kuralı
 *
 * Kullanım:
 * - 'ip' → Herhangi bir geçerli IP
 * - 'ip:v4' → Sadece IPv4
 * - 'ip:v6' → Sadece IPv6
 * - 'ip:no_priv' → Private IP adresleri kabul etmez
 * - 'ip:no_res' → Reserved IP adresleri kabul etmez
 * - 'ip:v4,no_priv' → IPv4 ve private olmayan
 */
final class Ip implements ParameterizedRuleInterface
{
    private string $version;
    private bool $allowPrivate;
    private bool $allowReserved;

    public function __construct(
        string $version = 'any',
        bool $allowPrivate = true,
        bool $allowReserved = true
    ) {
        $this->version = $version;
        $this->allowPrivate = $allowPrivate;
        $this->allowReserved = $allowReserved;
    }

    /**
     * Kural string'inden gelen parametreleri uygular (örn. 'ip:v4,no_priv').
     */
    public function setParameters(array $params): self
    {
        foreach ($params as $param) {
            $param = strtolower(trim((string) $param));

            if ($param === 'v4' || $param === 'v6') {
                $this->version = $param;
            } elseif ($param === 'no_priv') {
                $this->allowPrivate = false;
            } elseif ($param === 'no_res') {
                $this->allowReserved = false;
            }
        }

        return $this;
    }

    public function passes(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $flags = 0;

        // Version filtresi
        if ($this->version === 'v4') {
            $flags |= FILTER_FLAG_IPV4;
        } elseif ($this->version === 'v6') {
            $flags |= FILTER_FLAG_IPV6;
        }

        // Private range filtresi
        if (!$this->allowPrivate) {
            $flags |= FILTER_FLAG_NO_PRIV_RANGE;
        }

        // Reserved range filtresi
        if (!$this->allowReserved) {
            $flags |= FILTER_FLAG_NO_RES_RANGE;
        }

        return filter_var($value, FILTER_VALIDATE_IP, $flags) !== false;
    }

    public function message(): string
    {
        $msg = ':field geçerli bir IP adresi olmalıdır';

        if ($this->version === 'v4') {
            $msg = ':field geçerli bir IPv4 adresi olmalıdır';
        } elseif ($this->version === 'v6') {
            $msg = ':field geçerli bir IPv6 adresi olmalıdır';
        }

        return $msg;
    }

    /**
     * Public IP adresi kuralı (private/reserved olmayan)
     */
    public static function public(): self
    {
        return new self('any', false, false);
    }

    /**
     * IPv4 kuralı
     */
    public static function v4(): self
    {
        return new self('v4');
    }

    /**
     * IPv6 kuralı
     */
    public static function v6(): self
    {
        return new self('v6');
    }
    public function messageKey(): string
    {
        return 'validation.ip';
    }
}
