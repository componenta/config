<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Internal\ConfigMerger;
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

function env(
    string|ConfigPath $key,
    mixed $default = DefaultValue::None,
): EnvironmentEntry {
    return new EnvironmentEntry($key, $default);
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
 * @param array<array-key, mixed> $base
 * @param array<array-key, mixed> $override
 * @return array<array-key, mixed>
 */
function config_merge(array $base, array $override): array
{
    return ConfigMerger::merge($base, $override);
}
