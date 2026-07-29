<?php

declare(strict_types=1);

namespace Liminal\Support;

final class Env
{
    public static function string(string $key, string $default): string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = getenv($key);

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = getenv($key);

        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return (int) $value;
    }

    public static function nullableString(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
