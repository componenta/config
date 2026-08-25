<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

use Componenta\Config\ConfigKey;
use InvalidArgumentException;

/**
 * Deterministic configuration composition.
 *
 * Generic lists append while maps preserve both string and integer key identity.
 * The DI v5 dependency root uses section-specific merge rules so composition
 * cannot change the meaning of a valid dependency shape.
 *
 * @internal
 */
final class ConfigMerger
{
    private const int MAX_DEPTH = 64;

    /** @var array<string, true> */
    private const array ATOMIC_DEPENDENCY_MAPS = [
        ConfigKey::FACTORIES => true,
        ConfigKey::ALIASES => true,
        ConfigKey::SERVICES => true,
        ConfigKey::PARAMETER_RESOLVERS => true,
    ];

    /** @var array<string, true> */
    private const array ARRAY_DEPENDENCY_SECTIONS = [
        ConfigKey::FACTORIES => true,
        ConfigKey::INVOKABLES => true,
        ConfigKey::ALIASES => true,
        ConfigKey::DELEGATORS => true,
        ConfigKey::SERVICES => true,
        ConfigKey::PARAMETER_RESOLVERS => true,
        ConfigKey::ATTRIBUTE_DEFINITIONS => true,
        ConfigKey::ATTRIBUTE_CAPABILITIES => true,
    ];

    /** @var array<string, true> */
    private const array BOOL_DEPENDENCY_SECTIONS = [
        ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
        ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => true,
    ];

    /** @var array<string, true> */
    private const array LIST_DEPENDENCY_SECTIONS = [
        ConfigKey::ATTRIBUTE_DEFINITIONS => true,
        ConfigKey::ATTRIBUTE_CAPABILITIES => true,
    ];

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        self::assertMergeableDependencies($base);
        self::assertMergeableDependencies($override);

        return self::mergeArray($base, $override, root: true);
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private static function mergeArray(
        array $base,
        array $override,
        bool $root = false,
        int $depth = 0,
    ): array {
        self::assertDepth($depth);

        if ($base === []) {
            return $override;
        }
        if ($override === []) {
            return $base;
        }

        if (array_is_list($base) && array_is_list($override)) {
            return [...$base, ...$override];
        }

        foreach ($override as $key => $value) {
            if (array_key_exists($key, $base)
                && is_array($base[$key])
                && is_array($value)
            ) {
                $base[$key] = $root && $key === ConfigKey::DEPENDENCIES
                    ? self::mergeDependencies($base[$key], $value, $depth + 1)
                    : self::mergeArray($base[$key], $value, depth: $depth + 1);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private static function mergeDependencies(
        array $base,
        array $override,
        int $depth,
    ): array {
        self::assertDepth($depth);

        if ($base === []) {
            return $override;
        }

        foreach ($override as $key => $value) {
            if (!array_key_exists($key, $base)
                || !is_array($base[$key])
                || !is_array($value)
            ) {
                $base[$key] = $value;
                continue;
            }

            if (isset(self::ATOMIC_DEPENDENCY_MAPS[$key])) {
                $base[$key] = array_replace($base[$key], $value);
                continue;
            }

            if ($key === ConfigKey::INVOKABLES) {
                $base[$key] = self::mergeInvokables($base[$key], $value);
                continue;
            }

            if ($key === ConfigKey::DELEGATORS) {
                $base[$key] = self::mergeDelegators($base[$key], $value);
                continue;
            }

            if (isset(self::LIST_DEPENDENCY_SECTIONS[$key])) {
                $base[$key] = [...$base[$key], ...$value];
                continue;
            }

            $base[$key] = self::mergeArray($base[$key], $value, depth: $depth + 1);
        }

        return $base;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private static function mergeInvokables(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_int($key)) {
                $base[] = $value;
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private static function mergeDelegators(array $base, array $override): array
    {
        foreach ($override as $id => $pipeline) {
            if (!is_string($id) || !is_array($pipeline)) {
                throw new \LogicException('Validated delegator structure invariant was lost.');
            }

            if (!array_key_exists($id, $base)) {
                $base[$id] = $pipeline;
                continue;
            }

            $existing = $base[$id];
            if (!is_array($existing)) {
                throw new \LogicException('Validated delegator pipeline invariant was lost.');
            }

            $base[$id] = [...$existing, ...$pipeline];
        }

        return $base;
    }

    /** @param array<array-key, mixed> $config */
    private static function assertMergeableDependencies(array $config): void
    {
        if (!array_key_exists(ConfigKey::DEPENDENCIES, $config)) {
            return;
        }

        $dependencies = $config[ConfigKey::DEPENDENCIES];
        if (!is_array($dependencies)) {
            throw new InvalidArgumentException(sprintf(
                'Dependency root "%s" must be an array; got %s.',
                ConfigKey::DEPENDENCIES,
                get_debug_type($dependencies),
            ));
        }

        $allowed = array_fill_keys(ConfigKey::dependencyKeys(), true);

        foreach ($dependencies as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'Unsupported DI v5 dependency section "%s".',
                    (string) $key,
                ));
            }

            if (isset(self::ARRAY_DEPENDENCY_SECTIONS[$key]) && !is_array($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Dependency section "%s" must be an array; got %s.',
                    $key,
                    get_debug_type($value),
                ));
            }

            if (isset(self::BOOL_DEPENDENCY_SECTIONS[$key]) && !is_bool($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Dependency section "%s" must be bool; got %s.',
                    $key,
                    get_debug_type($value),
                ));
            }

            if (isset(self::LIST_DEPENDENCY_SECTIONS[$key])
                && is_array($value)
                && $value !== []
                && !array_is_list($value)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Dependency section "%s" must be a list.',
                    $key,
                ));
            }
        }

        if (!array_key_exists(ConfigKey::DELEGATORS, $dependencies)) {
            return;
        }

        /** @var array<array-key, mixed> $delegators */
        $delegators = $dependencies[ConfigKey::DELEGATORS];

        foreach ($delegators as $id => $pipeline) {
            if (!is_string($id) || $id === '') {
                throw new InvalidArgumentException(
                    'Delegator service ids must be non-empty strings.',
                );
            }

            if (!is_array($pipeline) || !array_is_list($pipeline)) {
                throw new InvalidArgumentException(sprintf(
                    'Delegators for "%s" must be configured as a pipeline list.',
                    $id,
                ));
            }

            if (self::isCallablePair($pipeline)) {
                throw new InvalidArgumentException(sprintf(
                    'Delegators for "%s" must be configured as a pipeline list; callable pairs must be nested as one pipeline entry.',
                    $id,
                ));
            }
        }
    }

    private static function assertDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf(
                'Configuration merge exceeds maximum nesting depth of %d.',
                self::MAX_DEPTH,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @phpstan-assert-if-true array{object|string, non-empty-string} $value
     */
    private static function isCallablePair(array $value): bool
    {
        if (array_keys($value) !== [0, 1]
            || !is_string($value[1])
            || $value[1] === ''
        ) {
            return false;
        }

        if (is_callable($value)) {
            return true;
        }

        return is_string($value[0])
            && $value[0] !== ''
            && (class_exists($value[0]) || interface_exists($value[0]))
            && method_exists($value[0], $value[1]);
    }

    private function __construct() {}
}
