<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

/** @internal */
final class TypeConverter
{
    private const array TRUTHY_VALUES = ['true', '1', 'yes', 'on', 'enabled', 'y'];
    private const array FALSY_VALUES = ['false', '0', 'no', 'off', 'disabled', 'n', ''];

    public static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (!is_string($value)) {
            return null;
        }

        $lower = strtolower(trim($value));

        if (in_array($lower, self::TRUTHY_VALUES, true)) {
            return true;
        }

        if (in_array($lower, self::FALSY_VALUES, true)) {
            return false;
        }

        return null;
    }

    public static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value) {
                return null;
            }

            $result = (int) $value;
            return (float) $result === $value ? $result : null;
        }

        if (!is_string($value)) {
            return null;
        }

        return self::parseIntegerString(trim($value));
    }

    public static function toFloat(mixed $value): ?float
    {
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $result = (float) $trimmed;
        return is_finite($result) ? $result : null;
    }

    public static function toString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    public static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return [];
            }

            return array_map('trim', explode(',', $trimmed));
        }

        if ($value === null) {
            return [];
        }

        return [$value];
    }

    private static function parseIntegerString(string $value): ?int
    {
        if (preg_match('/^([+-]?)(\d+)$/D', $value, $matches) !== 1) {
            return null;
        }

        $negative = $matches[1] === '-';
        $digits = ltrim($matches[2], '0');

        if ($digits === '') {
            return 0;
        }

        $limit = $negative
            ? substr((string) PHP_INT_MIN, 1)
            : (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
        ) {
            return null;
        }

        return (int) (($negative ? '-' : '') . $digits);
    }

    private function __construct() {}
}
