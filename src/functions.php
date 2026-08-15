<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Internal\ConfigMerger;
use Componenta\Config\Internal\TypeConverter;
use Psr\Container\ContainerInterface;

/**
 * Create a ConfigPath for dot notation access.
 *
 * @example
 * ```php
 * $config->get(path('database.host'));
 * $config->int(path('cache.ttl'));
 * ```
 */
function path(string $path): ConfigPath
{
    return new ConfigPath($path);
}

/**
 * Create a container-entry fallback for ContainerValue::find().
 *
 * @param class-string|null $type
 */
function entry(string $id, ?string $type = null): ContainerEntry
{
    return new ContainerEntry($id, $type);
}

/**
 * Create a config-entry fallback for Config::get() and ContainerValue::find().
 */
function config_entry(
    string|ConfigPath $key,
    mixed $default = DefaultValue::None,
): ConfigEntry {
    return new ConfigEntry($key, $default);
}

/**
 * Create an executable fallback callback. Plain callables remain values.
 */
function lazy(callable $callback, bool $cache = true): LazyValue
{
    return new LazyValue($callback, $cache);
}

/**
 * Get Config from PSR-11 container.
 */
function config(ContainerInterface $container): Config
{
    return $container->get(Config::class);
}

/**
 * Get environment variable with type conversion.
 *
 * Reads from $_ENV superglobal. For immutable access, use Environment class.
 *
 * Type conversions:
 * - Numeric strings -> int/float
 * - 'true', 'yes', 'on', 'enabled' -> true
 * - 'false', 'no', 'off', 'disabled' -> false
 *
 * @throws ConfigException If key is missing and no default provided
 */
function env(string $key, mixed $default = DefaultValue::None): mixed
{
    if (!array_key_exists($key, $_ENV)) {
        if ($default === DefaultValue::None) {
            throw ConfigException::missingKey($key);
        }

        return $default;
    }

    $value = $_ENV[$key];

    if (!is_string($value)) {
        return $value;
    }

    // Numeric conversion
    if (is_numeric($value)) {
        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    // Boolean conversion
    $lower = strtolower(trim($value));

    if (in_array($lower, ['true', 'yes', 'y', 'on', 'enabled', '1'], true)) {
        return true;
    }

    if (in_array($lower, ['false', 'no', 'n', 'off', 'disabled', '0'], true)) {
        return false;
    }

    return $value;
}

/**
 * Get environment variable as string.
 *
 * @throws ConfigException If key is missing and no default provided
 */
function env_string(string $key, string|DefaultValue $default = DefaultValue::None): string
{
    $value = env($key, $default);

    $result = TypeConverter::toString($value);

    return $result ?? (string) $value;
}

/**
 * Get environment variable as integer.
 *
 * @throws ConfigException If key is missing and no default provided
 */
function env_int(string $key, int|DefaultValue $default = DefaultValue::None): int
{
    $value = env($key, $default);

    $result = TypeConverter::toInt($value);

    if ($result === null) {
        throw new ConfigException("Cannot convert environment variable '$key' to int");
    }

    return $result;
}

/**
 * Get environment variable as float.
 *
 * @throws ConfigException If key is missing and no default provided
 */
function env_float(string $key, float|DefaultValue $default = DefaultValue::None): float
{
    $value = env($key, $default);

    $result = TypeConverter::toFloat($value);

    if ($result === null) {
        throw new ConfigException("Cannot convert environment variable '$key' to float");
    }

    return $result;
}

/**
 * Get environment variable as boolean.
 *
 * @throws ConfigException If key is missing and no default provided
 */
function env_bool(string $key, bool|DefaultValue $default = DefaultValue::None): bool
{
    $value = env($key, $default);

    $result = TypeConverter::toBool($value);

    if ($result === null) {
        throw new ConfigException("Cannot convert environment variable '$key' to bool");
    }

    return $result;
}

/**
 * Get environment variable as array (comma-separated).
 *
 * @throws ConfigException If key is missing and no default provided
 */
function env_array(string $key, array|DefaultValue $default = DefaultValue::None): array
{
    if (!array_key_exists($key, $_ENV)) {
        if ($default === DefaultValue::None) {
            throw ConfigException::missingKey($key);
        }

        return $default;
    }

    return TypeConverter::toArray($_ENV[$key]);
}

/**
 * Recursively merges two configuration arrays.
 *
 * Generic configuration keeps the historical semantics: string-keyed arrays
 * merge recursively and numeric keys append. The root `dependencies` section
 * uses schema-aware DI composition so semantic maps are not treated as lists or
 * recursively spliced opaque values.
 *
 * If the override array contains ConfigKey::OVERRIDE_INDEXES marker,
 * numeric keys are replaced by index instead of appended.
 *
 * @internal
 */
function config_merge(array $base, array $override): array
{
    return ConfigMerger::merge($base, $override);
}

/**
 * Populate $_ENV and $_SERVER superglobals.
 *
 * @internal
 *
 * @param array<string, string> $data
 */
function populate_env(array $data, bool $override = false, bool $populateServer = true): void
{
    foreach ($data as $key => $value) {
        if (!$override && isset($_ENV[$key])) {
            continue;
        }

        $_ENV[$key] = $value;

        if ($populateServer) {
            $_SERVER[$key] = $value;
        }
    }
}
