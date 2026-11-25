<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

use ReflectionReference;

/** @internal */
final class ArraySnapshot
{
    /**
     * @template TKey of array-key
     * @param array<TKey, mixed> $data
     * @param array<string, ReflectionReference|false> $previousVisibility
     * @return array{array<TKey, mixed>, ?array<string, ReflectionReference|false>}
     */
    public static function capture(array $data, array $previousVisibility = []): array
    {
        if ($data === []) {
            return [$data, null];
        }

        $pending = [$data];
        while ($pending !== []) {
            $branch = array_pop($pending);
            $slots = self::holdSlots($branch);
            foreach ($branch as $key => $value) {
                if (ReflectionReference::fromArrayElement($branch, $key) !== null) {
                    unset($slots);

                    return self::duplicate($data, $previousVisibility);
                }
                if (is_array($value)) {
                    $pending[] = $value;
                }
            }
            unset($slots);
        }

        return [$data, null];
    }

    /**
     * Copies array slots and rebuilds reference cells, including back edges.
     * Other values, such as objects and resources, retain their identity.
     *
     * @template TKey of array-key
     * @param array<TKey, mixed> $data
     * @return array<TKey, mixed>
     */
    public static function copy(array $data): array
    {
        return self::duplicate($data, null)[0];
    }

    /**
     * @template TKey of array-key
     * @param array<TKey, mixed> $data
     * @param array<string, ReflectionReference|false>|null $previousVisibility
     * @return array{array<TKey, mixed>, array<string, ReflectionReference|false>}
     */
    private static function duplicate(array $data, ?array $previousVisibility): array
    {
        $copy = [];
        $references = [];
        $visibility = [];
        /** @var list<array{array<array-key, mixed>, array<array-key, mixed>}> $pending */
        $pending = [[$data, &$copy]];
        while ($pending !== []) {
            $frame = array_pop($pending);
            [$source, &$target] = $frame;

            $visible = [];
            if ($previousVisibility !== null) {
                foreach ($source as $key => $value) {
                    if (is_array($value) && ReflectionReference::fromArrayElement($source, $key) !== null) {
                        $visible[$key] = true;
                    }
                }
            }

            $slots = self::holdSlots($source);
            foreach ($source as $key => $value) {
                $reference = ReflectionReference::fromArrayElement($source, $key);
                if ($reference !== null) {
                    $id = $reference->getId();
                    if (array_key_exists($id, $references)) {
                        $target[$key] = &$references[$id];
                        continue;
                    }

                    if (is_array($value)) {
                        $references[$id] = [];
                        $target[$key] = &$references[$id];
                        $pending[] = [$value, &$references[$id]];
                        if ($previousVisibility !== null) {
                            $copyReference = ReflectionReference::fromArrayElement($target, $key);
                            assert($copyReference !== null);

                            // Keep detectable references alive and remember references PHP previously hid.
                            $visibility[$copyReference->getId()] = isset($visible[$key])
                                && ($previousVisibility[$id] ?? null) !== false ? $copyReference : false;
                        }
                    } else {
                        $references[$id] = $value;
                        $target[$key] = &$references[$id];
                    }
                    continue;
                }

                if (is_array($value)) {
                    $target[$key] = [];
                    $pending[] = [$value, &$target[$key]];
                    continue;
                }

                $target[$key] = $value;
            }
            unset($target, $slots, $reference);
        }

        /** @var array<TKey, mixed> $copy */
        return [$copy, $visibility];
    }

    /**
     * A separate padded array temporarily holds every reference cell. Reflection
     * otherwise hides cells held by a single slot, including edges in mutual cycles.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function holdSlots(array $data): array
    {
        return array_pad($data, count($data) + 1, null);
    }

    private function __construct() {}
}
