<?php

declare(strict_types=1);

namespace System\Validation;

class DataAccessor
{
    public function get(array $data, string $key, mixed $default = null): mixed
    {
        if (!str_contains($key, '.')) {
            return $data[$key] ?? $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    public function set(array $data, string $key, mixed $value): array
    {
        if (!str_contains($key, '.')) {
            $data[$key] = $value;
            return $data;
        }

        $segments = explode('.', $key);
        $current = &$data;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $current[$segment] = $value;
            } else {
                $current[$segment] ??= [];
                $current = &$current[$segment];
            }
        }

        return $data;
    }
}
