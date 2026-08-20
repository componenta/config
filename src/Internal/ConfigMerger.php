<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

use Componenta\Config\ConfigKey;
use InvalidArgumentException;

/**
 * Deterministic configuration composition.
 *
 * Generic arrays merge recursively by string key and append numeric entries.
 * The DI v5 dependency root additionally preserves semantic map keys and
 * rejects shapes that cannot be composed without changing their meaning.
 *
 * @internal
 */
final class ConfigMerger
{
    /** @var array<string, true> */
    private const array ATOMIC_DEPENDENCY_MAPS = [
        ConfigKey::FACTORIES => true,
        ConfigKey::ALIASES => true,
        ConfigKey::SERVICES => true,
        ConfigKey::PARAMETER_RESOLVERS => true,
    ];

    public static function merge(array $base, array $override): array
    {
        self::assertMergeableDependencies($base);
        self::assertMergeableDependencies($override);

        return self::mergeArray($base, $override, root: true);
    }

    private static function mergeArray(array $base, array $override, bool $root = false): array
    {
        if ($base === []) {
            return $override;
        }

        foreach ($override as $key => $value) {
            if (is_int($key)) {
                $base[] = $value;
                continue;
            }

            if (!is_array($value)
                || !array_key_exists($key, $base)
                || !is_array($base[$key])
            ) {
                $base[$key] = $value;
                continue;
            }

            $base[$key] = $root && $key === ConfigKey::DEPENDENCIES
                ? self::mergeDependencies($base[$key], $value)
                : self::mergeArray($base[$key], $value);
        }

        return $base;
    }

    private static function mergeDependencies(array $base, array $override): array
    {
        if ($base === []) {
            return $override;
        }

        foreach ($override as $key => $value) {
            if (is_int($key)) {
                $base[] = $value;
                continue;
            }

            if (!is_array($value)
                || !array_key_exists($key, $base)
                || !is_array($base[$key])
            ) {
                $base[$key] = $value;
                continue;
            }

            if (isset(self::ATOMIC_DEPENDENCY_MAPS[$key])) {
                $base[$key] = array_replace($base[$key], $value);
                continue;
            }

            $base[$key] = self::mergeArray($base[$key], $value);
        }

        return $base;
    }

    /** @param array<array-key, mixed> $config */
    private static function assertMergeableDependencies(array $config): void
    {
        $dependencies = $config[ConfigKey::DEPENDENCIES] ?? null;
        if (!is_array($dependencies)) {
            return;
        }

        $delegators = $dependencies[ConfigKey::DELEGATORS] ?? null;
        if ($delegators === null || !is_array($delegators)) {
            return;
        }

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

    /** @phpstan-assert-if-true array{object|string, non-empty-string} $value */
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
