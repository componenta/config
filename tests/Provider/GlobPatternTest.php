<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Provider;

use Componenta\Config\Internal\GlobPattern;

it(
    'expands glob alternatives while preserving the pattern around them',
    /** @param list<string> $matches */
    function (string $pattern, array $matches): void {
        expect(iterator_to_array(GlobPattern::expandBraces($pattern), false))->toBe($matches);
    },
)->with([
    'plain glob' => ['config/*.php', ['config/*.php']],
    'nested and adjacent alternatives' => [
        'config/{a,{b,c}}.{php,json}',
        ['config/a.php', 'config/a.json', 'config/b.php', 'config/b.json', 'config/c.php', 'config/c.json'],
    ],
    'empty and repeated choices' => ['x{,a,a}.php', ['x.php', 'xa.php', 'xa.php']],
    'escaped braces and comma' => ['\\{dir\\}/{a\\,b,c}.php', ['\\{dir\\}/a\\,b.php', '\\{dir\\}/c.php']],
    'unclosed outer brace' => ['x{a,{b,c}.php', ['x{a,{b,c}.php']],
    'unmatched closing brace' => ['x}/{a,b}.php', ['x}/a.php', 'x}/b.php']],
]);
