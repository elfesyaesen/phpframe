<?php

declare(strict_types=1);

namespace System\Config;

use System\Engine\Env;

final readonly class EnvReader
{
    public function get(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }

    public function has(string $key): bool
    {
        return Env::has($key);
    }

    public function string(string $key, string $default = ''): string
    {
        $raw = Env::raw($key);

        if ($raw !== null) {
            return $raw;
        }

        $value = Env::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = Env::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = Env::get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = Env::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '' => $default,
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new \InvalidArgumentException(
                "{$key} geçersiz boolean değer: '{$value}' (true/false, 1/0, yes/no, on/off)"
            ),
        };
    }

    /**
     * @return list<string>
     */
    public function list(string $key, string $default = ''): array
    {
        $raw = $this->string($key, $default);

        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $item): bool => $item !== ''
        ));
    }

    /**
     * @return list<string>
     */
    public function shadowed(): array
    {
        return Env::shadowed();
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys(Env::all());
        sort($keys);

        return $keys;
    }
}
