<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

use Componenta\Config\ConfigKey;

/**
 * Deterministic configuration composition.
 *
 * Generic arrays merge recursively by string key and append numeric entries.
 * The root dependency section additionally treats maps whose keys carry
 * semantic identity as atomic maps, so later providers replace one entry
 * without recursively merging its value.
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

    private function __construct() {}
}
