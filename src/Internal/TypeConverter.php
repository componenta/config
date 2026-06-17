<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

/**
 * Converts values to specific types.
 *
 * @internal This class is not part of the public API
 */
final class TypeConverter
{
    private const array TRUTHY_VALUES = ['true', '1', 'yes', 'on', 'enabled', 'y'];
    private const array FALSY_VALUES = ['false', '0', 'no', 'off', 'disabled', 'n', ''];

    /**
     * Convert value to boolean (strict).
     *
     * `bool` values pass through. Strings are matched against the documented
     * TRUTHY/FALSY sets case-insensitively after trim. Any other input -
     * including unrecognised strings like `"undefined"`, `"null"`, `"yse"` -
     * returns null so the caller can raise an explicit error rather than
     * silently coercing via `(bool)$value` (which would treat any non-empty
     * string as `true` and hide typos).
     *
     * @return bool|null Null when the value is not unambiguously boolean.
     */
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

    /**
     * Convert value to integer.
     *
     * @return int|null Returns null if conversion not possible
     */
    public static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (int) $trimmed;
            }
        }

        return null;
    }

    /**
     * Convert value to float.
     *
     * @return float|null Returns null if conversion not possible
     */
    public static function toFloat(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            if (is_numeric($trimmed)) {
                return (float) $trimmed;
            }
        }

        return null;
    }

    /**
     * Convert value to string.
     *
     * @return string|null Returns null if conversion not possible
     */
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

    /**
     * Convert value to array.
     *
     * String values are split by comma.
     */
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

    /**
     * Check if value can be converted to integer.
     */
    public static function isConvertibleToInt(mixed $value): bool
    {
        return self::toInt($value) !== null;
    }

    /**
     * Check if value can be converted to float.
     */
    public static function isConvertibleToFloat(mixed $value): bool
    {
        return self::toFloat($value) !== null;
    }

    /**
     * Check if value can be converted to string.
     */
    public static function isConvertibleToString(mixed $value): bool
    {
        return self::toString($value) !== null;
    }
}
