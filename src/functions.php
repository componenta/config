<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Internal\ConfigMerger;
use Componenta\Config\Internal\TypeConverter;
use Psr\Container\ContainerInterface;

function path(string $path): ConfigPath
{
    return new ConfigPath($path);
}

/** @param class-string|null $type */
function entry(string $id, ?string $type = null): ContainerEntry
{
    return new ContainerEntry($id, $type);
}

function config_entry(
    int|string|ConfigPath $key,
    mixed $default = DefaultValue::None,
): ConfigEntry {
    return new ConfigEntry($key, $default);
}

function lazy(callable $callback, bool $cache = true): LazyValue
{
    return new LazyValue($callback, $cache);
}

function config(ContainerInterface $container): Config
{
    $config = $container->get(Config::class);

    if (!$config instanceof Config) {
        throw new ConfigException(sprintf(
            'Container entry "%s" must be an instance of %s; got %s.',
            Config::class,
            Config::class,
            get_debug_type($config),
        ));
    }

    return $config;
}

/**
 * Return the raw environment value using Componenta environment precedence.
 * Typed conversion belongs to env_string/env_int/env_float/env_bool/env_array.
 */
function env(string $key, mixed $default = DefaultValue::None): mixed
{
    $found = false;
    $value = raw_env_value($key, $found);

    if ($found) {
        return $value;
    }

    if ($default === DefaultValue::None) {
        throw ConfigException::missingKey($key);
    }

    return $default;
}

function env_string(
    string $key,
    string|DefaultValue $default = DefaultValue::None,
): string {
    $value = env($key, $default);
    $result = TypeConverter::toString($value);

    if ($result === null) {
        throw new ConfigException(sprintf(
            'Cannot convert environment variable "%s" to string.',
            $key,
        ));
    }

    return $result;
}

function env_int(
    string $key,
    int|DefaultValue $default = DefaultValue::None,
): int {
    $value = env($key, $default);
    $result = TypeConverter::toInt($value);

    if ($result === null) {
        throw new ConfigException(sprintf(
            'Cannot convert environment variable "%s" to int.',
            $key,
        ));
    }

    return $result;
}

function env_float(
    string $key,
    float|DefaultValue $default = DefaultValue::None,
): float {
    $value = env($key, $default);
    $result = TypeConverter::toFloat($value);

    if ($result === null) {
        throw new ConfigException(sprintf(
            'Cannot convert environment variable "%s" to float.',
            $key,
        ));
    }

    return $result;
}

function env_bool(
    string $key,
    bool|DefaultValue $default = DefaultValue::None,
): bool {
    $value = env($key, $default);
    $result = TypeConverter::toBool($value);

    if ($result === null) {
        throw new ConfigException(sprintf(
            'Cannot convert environment variable "%s" to bool.',
            $key,
        ));
    }

    return $result;
}

function env_array(
    string $key,
    array|DefaultValue $default = DefaultValue::None,
): array {
    return TypeConverter::toArray(env($key, $default));
}

function config_merge(array $base, array $override): array
{
    return ConfigMerger::merge($base, $override);
}

/** @internal @param array<string, mixed> $data */
function populate_env(array $data, bool $override = false): void
{
    foreach ($data as $key => $value) {
        if (!$override && environment_key_exists($key)) {
            continue;
        }

        $_ENV[$key] = $value;
    }
}

/** @internal */
function environment_key_exists(string $key): bool
{
    return array_key_exists($key, $_ENV)
        || array_key_exists($key, $_SERVER)
        || getenv($key) !== false;
}

/** @internal */
function raw_env_value(string $key, bool &$found): mixed
{
    if (array_key_exists($key, $_ENV)) {
        $found = true;
        return $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER)) {
        $found = true;
        return $_SERVER[$key];
    }

    $value = getenv($key);
    if ($value !== false) {
        $found = true;
        return $value;
    }

    $found = false;
    return null;
}
