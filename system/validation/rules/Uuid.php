<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Helpers\Uuid as UuidHelper;
use System\Validation\Contracts\RuleInterface;

/**
 * UUID biçim doğrulama kuralı.
 *
 * Kullanım: 'field' => 'required|string|uuid'
 * (Önceki 'regex:/^[0-9a-f]{8}-.../i' tekrarının yerine geçer.)
 */
final class Uuid implements RuleInterface
{
    public function passes(mixed $value): bool
    {
        return is_string($value) && UuidHelper::isValid($value);
    }

    public function message(): string
    {
        return ':field geçerli bir UUID olmalıdır';
    }

    public function messageKey(): string
    {
        return 'validation.uuid';
    }
}
