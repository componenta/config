<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigOverride;
use Componenta\Config\Exception\ConfigMergeException;
use ReflectionReference;

/**
 * Deterministic configuration composition.
 *
 * Generic lists append while maps preserve both string and integer key identity.
 * The reserved dependency root uses section-specific merge rules so composition
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
     * @param array<array-key, mixed> $base Previous merge result or an empty array.
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        self::assertNoReferences($override);
        self::assertMergeableDependencies($override);

        $baseHasDependencies = array_key_exists(ConfigKey::DEPENDENCIES, $base);
        $overrideHasDependencies = array_key_exists(ConfigKey::DEPENDENCIES, $override);

        /** @var array<array-key, mixed> $baseDependencies */
        $baseDependencies = $baseHasDependencies
            ? $base[ConfigKey::DEPENDENCIES]
            : [];
        /** @var array<array-key, mixed> $overrideDependencies */
        $overrideDependencies = $overrideHasDependencies
            ? $override[ConfigKey::DEPENDENCIES]
            : [];

        unset($base[ConfigKey::DEPENDENCIES], $override[ConfigKey::DEPENDENCIES]);

        $merged = self::mergeApplication($base, $override);

        if ($baseHasDependencies || $overrideHasDependencies) {
            $merged[ConfigKey::DEPENDENCIES] = match (true) {
                $baseHasDependencies && $overrideHasDependencies => self::mergeDependencies(
                    $baseDependencies,
                    $overrideDependencies,
                    depth: 1,
                ),
                $overrideHasDependencies => $overrideDependencies,
                default => $baseDependencies,
            };
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private static function mergeApplication(array $base, array $override): array
    {
        if ($base !== []
            && $override !== []
            && array_is_list($base) !== array_is_list($override)
        ) {
            return self::mergeArray([], $override);
        }

        return self::mergeArray($base, $override);
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     * @return array<array-key, mixed>
     */
    private static function mergeArray(
        array $base,
        array $override,
        int $depth = 0,
    ): array {
        self::assertDepth($depth);

        if ($override === []) {
            return $base;
        }

        if (array_is_list($base) && array_is_list($override)) {
            foreach ($override as $value) {
                if ($value instanceof ConfigOverride) {
                    throw new ConfigMergeException(
                        'ConfigOverride can only be used as a map value; lists are append-only.',
                    );
                }

                $base[] = is_array($value)
                    ? self::mergeArray([], $value, depth: $depth + 1)
                    : $value;
            }

            return $base;
        }

        foreach ($override as $key => $value) {
            if ($value instanceof ConfigOverride) {
                if ($value->removesKey()) {
                    unset($base[$key]);
                    continue;
                }

                $replacement = $value->replacement();
                $base[$key] = is_array($replacement)
                    ? self::mergeArray([], $replacement, depth: $depth + 1)
                    : $replacement;
                continue;
            }

            if (!array_key_exists($key, $base)) {
                $base[$key] = is_array($value)
                    ? self::mergeArray([], $value, depth: $depth + 1)
                    : $value;
                continue;
            }

            if (is_array($base[$key]) && is_array($value)) {
                if (array_is_list($base[$key]) !== array_is_list($value)) {
                    $base[$key] = self::mergeArray([], $value, depth: $depth + 1);
                    continue;
                }

                $base[$key] = self::mergeArray($base[$key], $value, depth: $depth + 1);
                continue;
            }

            $base[$key] = is_array($value)
                ? self::mergeArray([], $value, depth: $depth + 1)
                : $value;
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
            throw new ConfigMergeException(sprintf(
                'Dependency root "%s" must be an array; got %s.',
                ConfigKey::DEPENDENCIES,
                get_debug_type($dependencies),
            ));
        }

        foreach ($dependencies as $key => $value) {
            if (isset(self::ARRAY_DEPENDENCY_SECTIONS[$key])) {
                if (!is_array($value)) {
                    throw new ConfigMergeException(sprintf(
                        'Dependency section "%s" must be an array; got %s.',
                        $key,
                        get_debug_type($value),
                    ));
                }

                if (isset(self::LIST_DEPENDENCY_SECTIONS[$key]) && !array_is_list($value)) {
                    throw new ConfigMergeException(sprintf(
                        'Dependency section "%s" must be a list.',
                        $key,
                    ));
                }

                continue;
            }

            if (!isset(self::BOOL_DEPENDENCY_SECTIONS[$key])) {
                throw new ConfigMergeException(sprintf(
                    'Unsupported dependency section "%s".',
                    (string) $key,
                ));
            }

            if (!is_bool($value)) {
                throw new ConfigMergeException(sprintf(
                    'Dependency section "%s" must be bool; got %s.',
                    $key,
                    get_debug_type($value),
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
                throw new ConfigMergeException(
                    'Delegator service ids must be non-empty strings.',
                );
            }

            if (!is_array($pipeline) || !array_is_list($pipeline)) {
                throw new ConfigMergeException(sprintf(
                    'Delegators for "%s" must be configured as a pipeline list.',
                    $id,
                ));
            }
        }
    }

    private static function assertDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ConfigMergeException(sprintf(
                'Configuration merge exceeds maximum nesting depth of %d.',
                self::MAX_DEPTH,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<int|string> $path
     */
    private static function assertNoReferences(
        array $value,
        array $path = [],
        int $depth = 0,
    ): void {
        self::assertDepth($depth);

        foreach ($value as $key => $item) {
            if (ReflectionReference::fromArrayElement($value, $key) !== null) {
                throw new ConfigMergeException(sprintf(
                    'Configuration contains a PHP reference at %s.',
                    self::formatPath([...$path, $key]),
                ));
            }

            if (is_array($item)) {
                self::assertNoReferences($item, [...$path, $key], $depth + 1);
                continue;
            }

            if ($item instanceof ConfigOverride && !$item->removesKey()) {
                $replacement = $item->replacement();
                $visited = [];
                while ($replacement instanceof ConfigOverride && !$replacement->removesKey()) {
                    $id = spl_object_id($replacement);
                    if (isset($visited[$id])) {
                        break;
                    }

                    $visited[$id] = true;
                    $replacement = $replacement->replacement();
                }

                if (is_array($replacement)) {
                    self::assertNoReferences($replacement, [...$path, $key], $depth + 1);
                }
            }
        }
    }

    /** @param list<int|string> $path */
    private static function formatPath(array $path): string
    {
        $result = '$config';

        foreach ($path as $segment) {
            $result .= is_int($segment)
                ? sprintf('[%d]', $segment)
                : sprintf('[%s]', var_export($segment, true));
        }

        return $result;
    }

    private function __construct() {}
}
