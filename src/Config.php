<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\Internal\TypeConverter;
use Componenta\Arrayable\Arrayable;

/**
 * Immutable configuration container with dot notation support.
 *
 * Key access patterns:
 * - `string` key: literal key lookup ($data['database.host'])
 * - `ConfigPath` key: dot notation lookup ($data['database']['host'])
 *
 * Lazy values:
 * - Plain callables are values and are not executed automatically
 * - `LazyValue` wrappers receive the Config instance as parameter
 * - `LazyValue` results are cached by the wrapper by default
 *
 * Default value behavior:
 * - `DefaultValue::None`: throws ConfigException if key not found
 * - `ConfigEntry`: resolves another config key when the requested key is missing
 * - Any other value: returns default if key not found
 * - Plain callable defaults are returned as callable values and are not executed
 *
 * @example
 * ```php
 * $config = new Config(['app' => ['name' => 'MyApp', 'debug' => true]]);
 *
 * // Dot notation access
 * $name = $config->string(new ConfigPath('app.name'));
 *
 * // Literal key access
 * $value = $config->get('app.name'); // looks for $data['app.name']
 *
 * // With default
 * $timeout = $config->int(new ConfigPath('http.timeout'), 30);
 *
 * // Required (throws if missing)
 * $secret = $config->string(new ConfigPath('app.secret'));
 * ```
 */
final class Config implements \Countable, \IteratorAggregate, \ArrayAccess, Arrayable
{
    public function __construct(
        public readonly array $data,
        public readonly ?Environment $environment = null,
    ) {}

    /**
     * Get configuration value.
     *
     * @param string|ConfigPath $key String for literal key, ConfigPath for dot notation
     * @param mixed $default Default value or DefaultValue::None to require the key
     * @return mixed Configuration value
     * @throws ConfigException If key not found and default is DefaultValue::None
     */
    public function get(
        string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): mixed {
        $keyString = (string) $key;
        $found = false;
        $value = $this->resolveKey($key, $found);

        if ($found) {
            return $this->resolveValue($value);
        }

        if ($default === DefaultValue::None) {
            throw ConfigException::missingKey($keyString);
        }

        return $this->resolveDefault($default);
    }

    /**
     * Check if key exists.
     *
     * @param string|ConfigPath $key String for literal key, ConfigPath for dot notation
     */
    public function has(string|ConfigPath $key): bool
    {
        $found = false;
        $this->resolveKey($key, $found);

        return $found;
    }

    private function resolveDefault(mixed $default): mixed
    {
        if ($default instanceof ConfigEntry) {
            return $default->resolve($this);
        }

        return $this->resolveValue($default);
    }

    private function resolveValue(mixed $value): mixed
    {
        if ($value instanceof ResolvableValueInterface) {
            return $value->resolve($this);
        }

        return $value;
    }

    /**
     * Get value as string.
     *
     * @throws ConfigException If key not found and no default provided
     * @throws InvalidConfigValueException If value cannot be converted to string
     */
    public function string(
        string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): string {
        $value = $this->get($key, $default);

        $result = TypeConverter::toString($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'string', $value);
        }

        return $result;
    }

    /**
     * Get value as integer.
     *
     * @throws ConfigException If key not found and no default provided
     * @throws InvalidConfigValueException If value cannot be converted to int
     */
    public function int(
        string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): int {
        $value = $this->get($key, $default);

        $result = TypeConverter::toInt($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'int', $value);
        }

        return $result;
    }

    /**
     * Get value as float.
     *
     * @throws ConfigException If key not found and no default provided
     * @throws InvalidConfigValueException If value cannot be converted to float
     */
    public function float(
        string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): float {
        $value = $this->get($key, $default);

        $result = TypeConverter::toFloat($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'float', $value);
        }

        return $result;
    }

    /**
     * Get value as boolean.
     *
     * Truthy: 'true', '1', 'yes', 'on', 'enabled', 'y'
     * Falsy: 'false', '0', 'no', 'off', 'disabled', 'n', ''
     *
     * @throws ConfigException If key not found and no default provided
     */
    public function bool(
        string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): bool {
        $value = $this->get($key, $default);

        $result = TypeConverter::toBool($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'bool', $value);
        }

        return $result;
    }

    /**
     * Get value as array.
     *
     * String values are split by comma.
     *
     * @throws ConfigException If key not found and no default provided
     */
    public function array(
        string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): array {
        $value = $this->get($key, $default);

        return TypeConverter::toArray($value);
    }

    /**
     * Create new Config with only specified keys.
     *
     * @param string|ConfigPath|array<string|ConfigPath> $keys Keys to include
     */
    public function only(string|ConfigPath|array $keys): self
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $filtered = [];

        foreach ($keys as $key) {
            if (!$this->has($key)) {
                continue;
            }

            $found = false;
            $value = $this->resolveKey($key, $found);

            if ($key instanceof ConfigPath) {
                $this->setNestedValue($filtered, $key->toArray(), $value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return new self($filtered, $this->environment);
    }

    /**
     * Create new Config without specified keys.
     *
     * @param string|ConfigPath|array<string|ConfigPath> $keys Keys to exclude
     */
    public function except(string|ConfigPath|array $keys): self
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $filtered = $this->data;

        foreach ($keys as $key) {
            if ($key instanceof ConfigPath) {
                $this->unsetNestedValue($filtered, $key->toArray());
            } else {
                unset($filtered[$key]);
            }
        }

        return new self($filtered, $this->environment);
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Count top-level elements.
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Get iterator for top-level elements.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->data);
    }

    /**
     * ArrayAccess: Check if offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * ArrayAccess: Get value at offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * ArrayAccess: Set value (throws - config is immutable).
     *
     * @throws \RuntimeException Always
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException('Config is immutable');
    }

    /**
     * ArrayAccess: Unset value (throws - config is immutable).
     *
     * @throws \RuntimeException Always
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException('Config is immutable');
    }

    /**
     * Resolve key to value.
     *
     * @param string|ConfigPath $key Key to resolve
     * @param bool $found Set to true if key was found
     * @return mixed Value or null if not found
     */
    private function resolveKey(string|ConfigPath $key, bool &$found): mixed
    {
        if ($key instanceof ConfigPath) {
            return $this->resolvePathKey($key->toArray(), $found);
        }

        return $this->resolveLiteralKey($key, $found);
    }

    /**
     * Resolve literal string key.
     */
    private function resolveLiteralKey(string $key, bool &$found): mixed
    {
        if (array_key_exists($key, $this->data)) {
            $found = true;
            return $this->data[$key];
        }

        $found = false;
        return null;
    }

    /**
     * Resolve path with dot notation.
     *
     * @param string[] $segments Path segments
     */
    private function resolvePathKey(array $segments, bool &$found): mixed
    {
        $current = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $found = false;
                return null;
            }

            $current = $current[$segment];
            $current = $this->resolveValue($current);
        }

        $found = true;
        return $current;
    }

    /**
     * Set nested value in array using path segments.
     *
     * @param array $array Target array (by reference)
     * @param string[] $segments Path segments
     * @param mixed $value Value to set
     */
    private function setNestedValue(array &$array, array $segments, mixed $value): void
    {
        $current = &$array;

        foreach ($segments as $i => $segment) {
            if ($i === array_key_last($segments)) {
                $current[$segment] = $value;
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }
    }

    /**
     * Unset nested value in array using path segments.
     *
     * @param array $array Target array (by reference)
     * @param string[] $segments Path segments
     */
    private function unsetNestedValue(array &$array, array $segments): void
    {
        $current = &$array;
        $lastIndex = array_key_last($segments);

        foreach ($segments as $i => $segment) {
            if ($i === $lastIndex) {
                unset($current[$segment]);
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }

            $current = &$current[$segment];
        }
    }
}
