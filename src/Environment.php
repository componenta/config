<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\Internal\TypeConverter;

/**
 * Environment variable container.
 *
 * Provides typed access to environment variables loaded from .env files.
 * Keys may be passed as plain strings (literal lookup) or as {@see ConfigPath}
 * instances - a ConfigPath is collapsed to UPPER_SNAKE_CASE by joining its
 * segments with `_`, so `new ConfigPath('database.host')` resolves to
 * `DATABASE_HOST`. This keeps the Environment API symmetric with
 * {@see Config::get()} for callers that build paths uniformly.
 *
 * @example
 * ```php
 * $env = new Environment(['APP_DEBUG' => 'true', 'DB_PORT' => '3306']);
 *
 * $debug = $env->bool('APP_DEBUG');                   // true
 * $port  = $env->int(new ConfigPath('db.port'));            // looks up DB_PORT
 * ```
 */
final readonly class Environment implements \Countable, \IteratorAggregate
{
    public function __construct(
        private array $data,
    ) {}

    /**
     * Create Environment from $_ENV and $_SERVER globals.
     *
     * @param string[]|null $keys Filter to specific keys (null = all)
     */
    public static function fromGlobals(?array $keys = null): self
    {
        $data = $_ENV;

        foreach ($_SERVER as $key => $value) {
            if (!isset($data[$key]) && is_string($value)) {
                $data[$key] = $value;
            }
        }

        if ($keys !== null) {
            $data = array_intersect_key($data, array_flip($keys));
        }

        return new self($data);
    }

    /**
     * Get environment variable value.
     *
     * @param string|ConfigPath $key Environment variable name (ConfigPath -> joined with `_` and upper-cased).
     * @param mixed       $default Default value or DefaultValue::None to require the key.
     *
     * @throws ConfigException If key not found and default is DefaultValue::None
     */
    public function get(string|ConfigPath $key, mixed $default = DefaultValue::None): mixed
    {
        $name = $this->normalize($key);

        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        if ($default === DefaultValue::None) {
            throw ConfigException::missingKey($name);
        }

        return $default;
    }

    /**
     * Compare an environment variable value with the given value.
     *
     * Loose comparison (`==`) by default, strict (`===`) when `$strict` is true.
     *
     * @throws ConfigException If the key does not exist and no default is provided
     */
    public function match(
        string|ConfigPath $key,
        mixed $value,
        mixed $default = DefaultValue::None,
        bool $strict = false,
    ): bool {
        $env = $this->get($key, $default);

        return $strict ? $env === $value : $env == $value;
    }

    public function has(string|ConfigPath $key): bool
    {
        return array_key_exists($this->normalize($key), $this->data);
    }

    /**
     * @throws ConfigException
     * @throws InvalidConfigValueException
     */
    public function string(string|ConfigPath $key, string|DefaultValue $default = DefaultValue::None): string
    {
        $value  = $this->get($key, $default);
        $result = TypeConverter::toString($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert($this->normalize($key), 'string', $value);
        }

        return $result;
    }

    /**
     * @throws ConfigException
     * @throws InvalidConfigValueException
     */
    public function int(string|ConfigPath $key, int|DefaultValue $default = DefaultValue::None): int
    {
        $value  = $this->get($key, $default);
        $result = TypeConverter::toInt($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert($this->normalize($key), 'int', $value);
        }

        return $result;
    }

    /**
     * @throws ConfigException
     * @throws InvalidConfigValueException
     */
    public function float(string|ConfigPath $key, float|DefaultValue $default = DefaultValue::None): float
    {
        $value  = $this->get($key, $default);
        $result = TypeConverter::toFloat($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert($this->normalize($key), 'float', $value);
        }

        return $result;
    }

    /**
     * Truthy: 'true', '1', 'yes', 'on', 'enabled', 'y'
     * Falsy : 'false', '0', 'no', 'off', 'disabled', 'n', ''
     *
     * Any other string raises {@see InvalidConfigValueException} - silent
     * coercion via `(bool)` would let a typo turn a feature flag on
     * unintentionally.
     *
     * @throws ConfigException
     * @throws InvalidConfigValueException
     */
    public function bool(string|ConfigPath $key, bool|DefaultValue $default = DefaultValue::None): bool
    {
        $value  = $this->get($key, $default);
        $result = TypeConverter::toBool($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert($this->normalize($key), 'bool', $value);
        }

        return $result;
    }

    /**
     * @throws ConfigException
     */
    public function array(string|ConfigPath $key, array|DefaultValue $default = DefaultValue::None): array
    {
        $value = $this->get($key, $default);

        return TypeConverter::toArray($value);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    /**
     * Filter environment variables by key prefix.
     */
    public function prefix(string $prefix, bool $removePrefix = false): array
    {
        $result       = [];
        $prefixLength = strlen($prefix);

        foreach ($this->data as $key => $value) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $resultKey          = $removePrefix ? substr($key, $prefixLength) : $key;
            $result[$resultKey] = $value;
        }

        return $result;
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * Collapses a {@see ConfigPath} to its environment-variable form
     * (`new ConfigPath('db.host')` -> `DB_HOST`); strings pass through untouched.
     */
    private function normalize(string|ConfigPath $key): string
    {
        return $key instanceof ConfigPath ? strtoupper(implode('_', $key->toArray())) : $key;
    }
}
