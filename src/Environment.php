<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Arrayable\Arrayable;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\Internal\TypeConverter;
use InvalidArgumentException;

/**
 * Immutable snapshot of environment values.
 *
 * String keys are looked up literally. ConfigPath values are converted to
 * UPPER_SNAKE_CASE (`path('database.host')` becomes `DATABASE_HOST`).
 */
final readonly class Environment implements \Countable, \IteratorAggregate, Arrayable
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<array-key, mixed> $data */
    public function __construct(array $data)
    {
        foreach ($data as $key => $_value) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException(
                    'Environment keys must be non-empty strings.',
                );
            }
        }

        /** @var array<string, mixed> $data */
        $this->data = $data;
    }

    /** @param list<string>|null $keys */
    public static function fromGlobals(?array $keys = null): self
    {
        $data = [];
        $native = getenv();

        if (is_array($native)) {
            foreach ($native as $key => $value) {
                if (is_string($key) && $key !== '' && is_string($value)) {
                    $data[$key] = $value;
                }
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (is_string($key)
                && $key !== ''
                && (is_string($value) || is_int($value) || is_float($value) || is_bool($value))
            ) {
                $data[$key] = $value;
            }
        }

        foreach ($_ENV as $key => $value) {
            if (is_string($key) && $key !== '') {
                $data[$key] = $value;
            }
        }

        if ($keys !== null) {
            $data = array_intersect_key($data, array_fill_keys($keys, true));
        }

        return new self($data);
    }

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

    public function has(string|ConfigPath $key): bool
    {
        return array_key_exists($this->normalize($key), $this->data);
    }

    public function string(
        string|ConfigPath $key,
        string|DefaultValue $default = DefaultValue::None,
    ): string {
        $value = $this->get($key, $default);
        $result = TypeConverter::toString($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert(
                $this->normalize($key),
                'string',
                $value,
            );
        }

        return $result;
    }

    public function int(
        string|ConfigPath $key,
        int|DefaultValue $default = DefaultValue::None,
    ): int {
        $value = $this->get($key, $default);
        $result = TypeConverter::toInt($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert(
                $this->normalize($key),
                'int',
                $value,
            );
        }

        return $result;
    }

    public function float(
        string|ConfigPath $key,
        float|DefaultValue $default = DefaultValue::None,
    ): float {
        $value = $this->get($key, $default);
        $result = TypeConverter::toFloat($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert(
                $this->normalize($key),
                'float',
                $value,
            );
        }

        return $result;
    }

    public function bool(
        string|ConfigPath $key,
        bool|DefaultValue $default = DefaultValue::None,
    ): bool {
        $value = $this->get($key, $default);
        $result = TypeConverter::toBool($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert(
                $this->normalize($key),
                'bool',
                $value,
            );
        }

        return $result;
    }

    public function array(
        string|ConfigPath $key,
        array|DefaultValue $default = DefaultValue::None,
    ): array {
        return TypeConverter::toArray($this->get($key, $default));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    /** @return array<string, mixed> */
    public function prefix(string $prefix, bool $removePrefix = false): array
    {
        $result = [];
        $length = strlen($prefix);

        foreach ($this->data as $key => $value) {
            if (!str_starts_with($key, $prefix)) {
                continue;
            }

            $result[$removePrefix ? substr($key, $length) : $key] = $value;
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

    private function normalize(string|ConfigPath $key): string
    {
        return $key instanceof ConfigPath
            ? strtoupper(implode('_', $key->toArray()))
            : $key;
    }
}
