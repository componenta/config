<?php

declare(strict_types=1);

namespace Componenta\Config\Internal;

/** @internal */
final class GlobPattern
{
    /** @return iterable<string> */
    public static function expandBraces(string $pattern): iterable
    {
        $depth = 0;
        $opening = 0;
        $start = 0;
        $alternatives = [];
        $length = strlen($pattern);

        for ($index = 0; $index < $length; ++$index) {
            switch ($pattern[$index]) {
                case '\\':
                    ++$index;
                    break;
                case '{':
                    if ($depth === 0) {
                        $opening = $index;
                        $start = $index + 1;
                    }
                    ++$depth;
                    break;
                case ',':
                    if ($depth === 1) {
                        $alternatives[] = substr($pattern, $start, $index - $start);
                        $start = $index + 1;
                    }
                    break;
                case '}':
                    if ($depth === 0) {
                        break;
                    }
                    --$depth;
                    if ($depth > 0) {
                        break;
                    }

                    $alternatives[] = substr($pattern, $start, $index - $start);
                    $prefix = substr($pattern, 0, $opening);
                    $suffix = substr($pattern, $index + 1);
                    foreach ($alternatives as $alternative) {
                        yield from self::expandBraces($prefix . $alternative . $suffix);
                    }

                    return;
            }
        }

        yield $pattern;
    }

    private function __construct() {}
}
