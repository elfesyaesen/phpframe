<?php

declare(strict_types=1);

namespace System\Validation\Rules;

use System\Validation\Contracts\ParameterizedRuleInterface;

/**
 * Güçlü Şifre Kuralı
 *
 * Varsayılan gereksinimler:
 * - En az 8 karakter
 * - En az 1 büyük harf
 * - En az 1 küçük harf
 * - En az 1 rakam
 * - En az 1 özel karakter
 *
 * Kullanım:
 * - 'password' → Varsayılan kurallar
 * - 'password:12' → Minimum 12 karakter
 * - 'password:8,simple' → Sadece uzunluk kontrolü
 */
final class Password implements ParameterizedRuleInterface
{
    private int $minLength = 8;
    private bool $requireUppercase = true;
    private bool $requireLowercase = true;
    private bool $requireNumber = true;
    private bool $requireSpecial = true;
    private string $failedRule = '';

    public function setParameters(array $params): self
    {
        if (isset($params[0]) && is_numeric($params[0])) {
            $this->minLength = (int) $params[0];
        }
        if (isset($params[1]) && $params[1] === 'simple') {
            $this->requireUppercase = false;
            $this->requireLowercase = false;
            $this->requireNumber = false;
            $this->requireSpecial = false;
        }
        return $this;
    }

    public function passes(mixed $value): bool
    {
        if (!is_string($value)) {
            $this->failedRule = 'string';
            return false;
        }

        // Minimum uzunluk
        if (mb_strlen($value) < $this->minLength) {
            $this->failedRule = 'length';
            return false;
        }

        // Büyük harf kontrolü
        if ($this->requireUppercase && !preg_match('/[A-Z]/', $value)) {
            $this->failedRule = 'uppercase';
            return false;
        }

        // Küçük harf kontrolü
        if ($this->requireLowercase && !preg_match('/[a-z]/', $value)) {
            $this->failedRule = 'lowercase';
            return false;
        }

        // Rakam kontrolü
        if ($this->requireNumber && !preg_match('/[0-9]/', $value)) {
            $this->failedRule = 'number';
            return false;
        }

        // Özel karakter kontrolü
        if ($this->requireSpecial && !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':\"\\\\|,.<>\/?]/', $value)) {
            $this->failedRule = 'special';
            return false;
        }

        // Yaygın şifreleri kontrol et
        if ($this->isCommonPassword($value)) {
            $this->failedRule = 'common';
            return false;
        }

        return true;
    }

    public function message(): string
    {
        return match ($this->failedRule) {
            'string' => ':field bir metin olmalıdır',
            'length' => ':field en az ' . $this->minLength . ' karakter olmalıdır',
            'uppercase' => ':field en az bir büyük harf içermelidir',
            'lowercase' => ':field en az bir küçük harf içermelidir',
            'number' => ':field en az bir rakam içermelidir',
            'special' => ':field en az bir özel karakter içermelidir (!@#$%^&* vb.)',
            'common' => ':field çok yaygın bir şifre, lütfen daha güçlü bir şifre seçin',
            default => ':field güçlü bir şifre olmalıdır',
        };
    }

    /**
     * Yaygın şifreleri kontrol et
     */
    private function isCommonPassword(string $password): bool
    {
        // Karmaşıklık kurallarını (büyük/küçük/rakam/özel) geçebilen ama yine de
        // çok yaygın olan zayıf şifreler. Kapsamlı değil; en sık görülenleri eler.
        // Daha geniş kapsam için harici bir liste (ör. SecLists) yüklenebilir.
        static $common = [
            'password', 'password1', 'password1!', 'password123', 'password123!',
            'qwerty', 'qwerty123', 'qwerty123!', 'qwerty1!', 'azerty123',
            '12345678', '123456789', '1234567890', '11111111', '00000000',
            'admin123', 'admin123!', 'administrator', 'welcome1', 'welcome123',
            'welcome1!', 'iloveyou', 'letmein1', 'letmein123', 'abc12345',
            'abcd1234', 'passw0rd', 'passw0rd!', 'p@ssw0rd', 'p@ssword',
            'changeme', 'changeme1', 'sifre123', 'parola123', 'qwe123!',
            'test1234', 'master123', 'dragon123', 'monkey123', 'football1',
        ];

        return in_array(strtolower($password), $common, true);
    }

    /**
     * Basit şifre kuralı (sadece uzunluk)
     */
    public static function simple(int $minLength = 8): self
    {
        $instance = new self();
        $instance->setParameters([(string) $minLength, 'simple']);
        return $instance;
    }

    /**
     * Güçlü şifre kuralı
     */
    public static function strong(int $minLength = 12): self
    {
        $instance = new self();
        $instance->setParameters([(string) $minLength]);
        return $instance;
    }
    public function messageKey(): string
    {
        return match ($this->failedRule) {
            'string'    => 'validation.string',
            'length'    => 'validation.password.length',
            'uppercase' => 'validation.password.uppercase',
            'lowercase' => 'validation.password.lowercase',
            'number'    => 'validation.password.number',
            'special'   => 'validation.password.special',
            'common'    => 'validation.password.common',
            default     => 'validation.password.length',
        };
    }

    public function messageParams(): array
    {
        return ['min' => $this->minLength];
    }
}
