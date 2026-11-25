<?php

declare(strict_types=1);

namespace Componenta\Config;

use Componenta\Arrayable\Arrayable;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\ConfigResolutionException;
use Componenta\Config\Exception\InvalidConfigArgumentException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\Internal\ArraySnapshot;
use Componenta\Config\Internal\TypeConverter;
use Fiber;
use ReflectionReference;
use stdClass;
use WeakMap;

/**
 * Immutable runtime configuration snapshot.
 *
 * Literal integer/string keys address top-level values directly. ConfigPath is
 * reserved for nested access. Environment is always present and represents the
 * runtime environment snapshot associated with this configuration.
 *
 * @implements \IteratorAggregate<array-key, mixed>
 * @implements \ArrayAccess<mixed, mixed>
 */
final class Config implements \Countable, \IteratorAggregate, \ArrayAccess, Arrayable
{
    private const int MAX_RESOLUTION_DEPTH = 64;

    private readonly object $mainResolutionContext;

    /** @var WeakMap<object, list<array{key: string, label: string}>>|null */
    private ?WeakMap $resolutionStacks = null;

    /** @var array<array-key, mixed> */
    private array $values;

    /** @var array<string, ReflectionReference|false>|null */
    private ?array $referenceVisibility;

    /** @var array<array-key, mixed> */
    public array $data {
        get => $this->referenceVisibility !== null ? ArraySnapshot::copy($this->values) : $this->values;
    }

    /** @param array<array-key, mixed> $data */
    public function __construct(
        array $data,
        public readonly Environment $environment,
    ) {
        [$this->values, $this->referenceVisibility] = ArraySnapshot::capture($data);
        $this->mainResolutionContext = new stdClass();
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        if ($this->resolutionStacks !== null) {
            throw new \Exception("Serialization of 'WeakMap' is not allowed");
        }

        return [
            "\0" . self::class . "\0mainResolutionContext" => new stdClass(),
            "\0" . self::class . "\0resolutionStacks" => null,
            'data' => $this->data,
            'environment' => $this->environment,
        ];
    }

    /** @param array{data: array<array-key, mixed>, environment: Environment} $state */
    public function __unserialize(array $state): void
    {
        $this->__construct($state['data'], $state['environment']);
    }

    public function __clone(): void
    {
        $this->mainResolutionContext = new stdClass();
        $this->resolutionStacks = null;
    }

    public function get(
        int|string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): mixed {
        $context = Fiber::getCurrent() ?? $this->mainResolutionContext;
        $this->beginResolution($context, $key);

        try {
            $found = false;
            $value = $this->resolveKey($key, $found);

            if ($found) {
                return $this->resolveValue($value, [(string) $key]);
            }

            if ($default === DefaultValue::None) {
                throw ConfigException::missingKey((string) $key);
            }

            return $this->resolveDefault($default);
        } finally {
            $this->endResolution($context);
        }
    }

    public function has(int|string|ConfigPath $key): bool
    {
        $found = false;
        $this->resolveKey($key, $found);

        return $found;
    }

    public function string(
        int|string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): string {
        $value = $this->get($key, $default);
        $result = TypeConverter::toString($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'string', $value);
        }

        return $result;
    }

    public function int(
        int|string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): int {
        $value = $this->get($key, $default);
        $result = TypeConverter::toInt($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'int', $value);
        }

        return $result;
    }

    public function float(
        int|string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): float {
        $value = $this->get($key, $default);
        $result = TypeConverter::toFloat($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'float', $value);
        }

        return $result;
    }

    public function bool(
        int|string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): bool {
        $value = $this->get($key, $default);
        $result = TypeConverter::toBool($value);

        if ($result === null) {
            throw InvalidConfigValueException::cannotConvert((string) $key, 'bool', $value);
        }

        return $result;
    }

    /** @return array<array-key, mixed> */
    public function array(
        int|string|ConfigPath $key,
        mixed $default = DefaultValue::None,
    ): array {
        return TypeConverter::toArray($this->get($key, $default));
    }

    /** @param int|string|ConfigPath|array<int|string|ConfigPath> $keys */
    public function only(int|string|ConfigPath|array $keys): self
    {
        $keys = is_array($keys) ? $keys : [$keys];
        // Selections may descend into reference cells in the copied arrays.
        $source = $this->referenceVisibility === null ? $this : $this->filtered($this->values);
        /** @var array<array-key, mixed> $filtered */
        $filtered = [];

        foreach ($keys as $key) {
            $found = false;
            $value = $source->resolveKey($key, $found);
            if (!$found) {
                continue;
            }

            if ($key instanceof ConfigPath) {
                $this->setNestedValue($filtered, $key->toArray(), $value);
            } else {
                $filtered[$key] = $value;
            }
        }

        return $source->filtered($filtered);
    }

    /** @param int|string|ConfigPath|array<int|string|ConfigPath> $keys */
    public function except(int|string|ConfigPath|array $keys): self
    {
        $keys = is_array($keys) ? $keys : [$keys];
        $source = $this->referenceVisibility === null ? $this : $this->filtered($this->values);
        $filtered = $source->values;

        foreach ($keys as $key) {
            if ($key instanceof ConfigPath) {
                $this->unsetNestedValue($filtered, $key->toArray());
            } else {
                unset($filtered[$key]);
            }
        }

        return $source->filtered($filtered);
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function count(): int
    {
        return count($this->values);
    }

    /** @return \Traversable<array-key, mixed> */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->data);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($this->normalizeOffset($offset));
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($this->normalizeOffset($offset));
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException('Config is immutable');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException('Config is immutable');
    }

    private function resolveDefault(mixed $default): mixed
    {
        return $this->resolveValue($default, ['default']);
    }

    /** @param non-empty-list<string> $path */
    private function resolveValue(mixed $value, array $path): mixed
    {
        return $this->resolveValueRecursive($value, $path);
    }

    /**
     * @param non-empty-list<string> $path
     * @param array<int, array{position: int, descriptor: object}> $positions
     * @param list<string> $labels
     * @param array<string, true> $arrayReferences
     */
    private function resolveValueRecursive(
        mixed $value,
        array $path,
        array $positions = [],
        array $labels = [],
        array $arrayReferences = [],
        int $depth = 0,
    ): mixed {
        while (true) {
            if ($value instanceof EnvironmentEntry
                || $value instanceof ConfigEntry
                || $value instanceof LazyValue
            ) {
                if ($depth >= self::MAX_RESOLUTION_DEPTH) {
                    throw ConfigResolutionException::maximumDepth(
                        $path,
                        self::MAX_RESOLUTION_DEPTH,
                    );
                }

                ++$depth;
                $identity = spl_object_id($value);
                $label = match (true) {
                    $value instanceof EnvironmentEntry => 'EnvironmentEntry',
                    $value instanceof ConfigEntry => 'ConfigEntry',
                    default => 'LazyValue',
                };

                if (array_key_exists($identity, $positions)
                    && $positions[$identity]['descriptor'] === $value
                ) {
                    $path = array_slice(
                        $labels,
                        $positions[$identity]['position'],
                    );
                    $path[] = $label;

                    /** @var non-empty-list<string> $path */
                    throw ConfigResolutionException::descriptorCycle($path);
                }

                $positions[$identity] = [
                    'position' => count($labels),
                    'descriptor' => $value,
                ];
                $labels[] = $label;
            }

            if ($value instanceof EnvironmentEntry) {
                $value = $value->resolve($this->environment);
                continue;
            }

            if ($value instanceof ConfigEntry) {
                $value = $value->resolve($this);
                continue;
            }

            if ($value instanceof LazyValue) {
                $value = $value->resolve($this);
                continue;
            }

            if (is_array($value)) {
                if ($depth >= self::MAX_RESOLUTION_DEPTH) {
                    throw ConfigResolutionException::maximumDepth(
                        $path,
                        self::MAX_RESOLUTION_DEPTH,
                    );
                }

                $resolved = [];

                foreach ($value as $key => $item) {
                    $itemPath = [...$path, (string) $key];
                    $childReferences = $arrayReferences;
                    $reference = ReflectionReference::fromArrayElement($value, $key);

                    if ($reference !== null) {
                        $referenceId = $reference->getId();

                        if (($this->referenceVisibility[$referenceId] ?? null) !== false) {
                            if (isset($arrayReferences[$referenceId])) {
                                throw ConfigResolutionException::arrayCycle($itemPath);
                            }

                            $childReferences[$referenceId] = true;
                        }
                    }

                    $resolved[$key] = $this->resolveValueRecursive(
                        $item,
                        $itemPath,
                        $positions,
                        $labels,
                        $childReferences,
                        $depth + 1,
                    );
                }

                return $resolved;
            }

            return $value;
        }
    }

    private function resolveKey(int|string|ConfigPath $key, bool &$found): mixed
    {
        if ($key instanceof ConfigPath) {
            return $this->resolvePathKey($key->toArray(), $found);
        }

        if (array_key_exists($key, $this->values)) {
            $found = true;
            return $this->values[$key];
        }

        $found = false;
        return null;
    }

    /** @param list<string> $segments */
    private function resolvePathKey(array $segments, bool &$found): mixed
    {
        $current = $this->values;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                $found = false;
                return null;
            }

            $current = $current[$segment];
        }

        $found = true;
        return $current;
    }

    /** @param array<array-key, mixed> $data */
    private function filtered(array $data): self
    {
        $snapshot = clone $this;
        if ($this->referenceVisibility === null) {
            $snapshot->values = $data;
        } else {
            [$snapshot->values, $snapshot->referenceVisibility] = ArraySnapshot::capture($data, $this->referenceVisibility);
        }

        return $snapshot;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<string> $segments
     */
    private function setNestedValue(array &$array, array $segments, mixed $value): void
    {
        $current = &$array;
        $lastIndex = array_key_last($segments);

        foreach ($segments as $i => $segment) {
            if ($i === $lastIndex) {
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
     * @param array<array-key, mixed> $array
     * @param list<string> $segments
     */
    private function unsetNestedValue(array &$array, array $segments): void
    {
        $current = &$array;
        $lastIndex = array_key_last($segments);

        foreach ($segments as $i => $segment) {
            if (!is_array($current)) {
                return;
            }

            if ($i === $lastIndex) {
                unset($current[$segment]);
                return;
            }

            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                return;
            }

            // Detach reference slots before editing a branch of the filtered copy.
            $current = array_map(static fn(mixed $value): mixed => $value, $current);

            $current = &$current[$segment];
        }
    }

    private function normalizeOffset(mixed $offset): int|string|ConfigPath
    {
        if (is_int($offset) || is_string($offset) || $offset instanceof ConfigPath) {
            return $offset;
        }

        throw new InvalidConfigArgumentException(sprintf(
            'Config offset must be int, string or %s; got %s.',
            ConfigPath::class,
            get_debug_type($offset),
        ));
    }

    private function beginResolution(object $context, int|string|ConfigPath $key): void
    {
        $stacks = $this->resolutionStacks ??= new WeakMap();
        $stack = $stacks[$context] ?? [];
        $identity = $key instanceof ConfigPath
            ? 'path:' . $key->value
            : get_debug_type($key) . ':' . (string) $key;
        $label = (string) $key;

        foreach ($stack as $index => $entry) {
            if ($entry['key'] !== $identity) {
                continue;
            }

            $path = array_column(array_slice($stack, $index), 'label');
            $path[] = $label;

            /** @var non-empty-list<string> $path */
            throw ConfigResolutionException::cycle($path);
        }

        $stack[] = ['key' => $identity, 'label' => $label];
        $stacks[$context] = $stack;
    }

    private function endResolution(object $context): void
    {
        $stacks = $this->resolutionStacks;
        if ($stacks === null || !isset($stacks[$context])) {
            return;
        }

        $stack = $stacks[$context];
        array_pop($stack);

        if ($stack === []) {
            unset($stacks[$context]);
            return;
        }

        $stacks[$context] = $stack;
    }
}
