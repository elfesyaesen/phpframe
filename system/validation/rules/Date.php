<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\RuleInterface;
use DateTimeImmutable;

class Date implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        // Round-trip kontrolü: createFromFormat taşmayı (2026-13-45 → 2027-02-14)
        // sessizce kabul eder. Parse edilen değeri aynı formata geri yazıp girdiyle
        // karşılaştırarak gerçekten geçerli tarihleri ayıkla. '!' kalan alanları sıfırlar.
        foreach (['Y-m-d', 'Y-m-d H:i:s'] as $format) {
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($dt !== false && $dt->format($format) === $value) {
                return true;
            }
        }

        return false;
    }

    public function message(): string
    {
        return ':field gecerli bir tarih olmalidir';
    }
    public function messageKey(): string
    {
        return 'validation.date';
    }
}
