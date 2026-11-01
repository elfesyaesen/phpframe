<?php

declare(strict_types=1);

namespace System\Security;

use SensitiveParameter;

final class PasswordHasher
{
    private const ALGORITHM = PASSWORD_DEFAULT;

    private const GENERATED_LENGTH = 16;

    private const UPPER   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER   = 'abcdefghijkmnpqrstuvwxyz';
    private const DIGITS  = '23456789';
    private const SPECIAL = '!@#$%&*';

    public function hash(#[SensitiveParameter] string $plain): string
    {
        return password_hash($plain, self::ALGORITHM);
    }

    public function verify(#[SensitiveParameter] string $plain, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($plain, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGORITHM);
    }

    public function generate(int $length = self::GENERATED_LENGTH): string
    {
        $classes = [self::UPPER, self::LOWER, self::DIGITS, self::SPECIAL];

        $length = max($length, count($classes));

        $characters = [];

        foreach ($classes as $class) {
            $characters[] = $class[random_int(0, strlen($class) - 1)];
        }

        $alphabet = self::UPPER . self::LOWER . self::DIGITS . self::SPECIAL;

        for ($i = count($classes); $i < $length; $i++) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
