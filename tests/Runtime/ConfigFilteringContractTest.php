<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;

it('excluding nested keys does not mutate referenced arrays in the source configuration', function (): void {
    $shared = ['keep' => 'kept', 'secret' => 'original'];
    $config = new Config([
        'before' => 1,
        'branches' => [7 => &$shared],
        'other' => &$shared,
        'after' => 2,
    ], new Environment([]));

    $filtered = $config->except([new ConfigPath('branches.7.secret'), new ConfigPath('other.secret')]);

    expect($filtered->toArray())->toBe([
        'before' => 1,
        'branches' => [7 => ['keep' => 'kept']],
        'other' => ['keep' => 'kept'],
        'after' => 2,
    ])->and($config->toArray())->toBe([
        'before' => 1,
        'branches' => [7 => ['keep' => 'kept', 'secret' => 'original']],
        'other' => ['keep' => 'kept', 'secret' => 'original'],
        'after' => 2,
    ])->and($shared)->toBe(['keep' => 'kept', 'secret' => 'original']);
});

it('preserves sibling paths when selecting and ignores missing or scalar paths when excluding', function (): void {
    $config = new Config(['settings' => ['host' => 'localhost', 'port' => 5432], 'scalar' => 5], new Environment([]));

    expect($config->only([new ConfigPath('settings.host'), new ConfigPath('settings.port'), 'missing'])->toArray())
        ->toBe(['settings' => ['host' => 'localhost', 'port' => 5432]])
        ->and($config->only('scalar')->toArray())->toBe(['scalar' => 5])
        ->and($config->except('scalar')->toArray())->toBe(['settings' => ['host' => 'localhost', 'port' => 5432]])
        ->and($config->except([new ConfigPath('missing.key'), new ConfigPath('scalar.key')])->toArray())->toBe($config->toArray());
});

it('supports nested ArrayAccess with null values and missing defaults', function (): void {
    $config = new Config(['settings' => ['nullable' => null]], new Environment([]));
    $path = new ConfigPath('settings.nullable');

    expect(isset($config[$path]))->toBeTrue()
        ->and($config[$path])->toBeNull()
        ->and($config->has(new ConfigPath('settings.missing')))->toBeFalse()
        ->and($config->get(new ConfigPath('settings.missing'), 'fallback'))->toBe('fallback')
        ->and($config->get(new ConfigPath('settings.nullable.child'), 'fallback'))->toBe('fallback');
});
