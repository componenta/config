<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigArgumentException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\LazyValue;
use RuntimeException;
use stdClass;

it('distinguishes literal keys from ConfigPath lookup', function (): void {
    $config = new Config([
        'database.host' => 'literal',
        'database' => ['host' => 'nested'],
        0 => 'first',
    ], new Environment([]));

    expect($config->get('database.host'))->toBe('literal')
        ->and($config->get(new ConfigPath('database.host')))->toBe('nested')
        ->and($config->get(0))->toBe('first');
});

it('uses array_key_exists semantics so null is an existing value', function (): void {
    $config = new Config(['nullable' => null], new Environment([]));

    expect($config->has('nullable'))->toBeTrue()
        ->and($config->get('nullable'))->toBeNull();
});

it('retains the explicitly supplied environment snapshot by identity', function (): void {
    $environment = new Environment(['APP_ENV' => 'test']);
    $config = new Config(['app' => 'test'], $environment);

    expect($config->environment)->toBe($environment);
});

it('throws for a required missing value and resolves ordinary defaults', function (): void {
    $config = new Config([], new Environment([]));

    expect(fn() => $config->get('missing'))
        ->toThrow(ConfigException::class, "Configuration key 'missing' is missing")
        ->and($config->get('missing', 'fallback'))->toBe('fallback');
});

it('provides typed accessors without lossy integer conversion', function (): void {
    $config = new Config([
        'string' => 42,
        'int' => '3306',
        'integral-float' => 3.0,
        'fractional-float' => 3.9,
        'float' => '3.14',
        'bool' => 'yes',
        'array' => 'redis, file',
    ], new Environment([]));

    expect($config->string('string'))->toBe('42')
        ->and($config->int('int'))->toBe(3306)
        ->and($config->int('integral-float'))->toBe(3)
        ->and(fn() => $config->int('fractional-float'))
        ->toThrow(InvalidConfigValueException::class)
        ->and($config->float('float'))->toBe(3.14)
        ->and($config->bool('bool'))->toBeTrue()
        ->and($config->array('array'))->toBe(['redis', 'file']);
});

it('rejects invalid typed conversion instead of silently coercing', function (): void {
    $config = new Config(
        ['string' => ['array'], 'bool' => 'definitely'],
        new Environment([]),
    );

    expect(fn() => $config->string('string'))
        ->toThrow(InvalidConfigValueException::class)
        ->and(fn() => $config->bool('bool'))
        ->toThrow(InvalidConfigValueException::class);
});

it('resolves ConfigEntry defaults against the same configuration', function (): void {
    $config = new Config(
        ['fallback' => ['name' => 'Componenta'], 0 => 'zero'],
        new Environment([]),
    );

    expect($config->string(
        'missing',
        new ConfigEntry(new ConfigPath('fallback.name')),
    ))->toBe('Componenta')
        ->and($config->string('also-missing', new ConfigEntry(0)))->toBe('zero');
});

it('returns plain callable values without executing them', function (): void {
    $callback = static fn(): string => 'not executed';
    $config = new Config(['callback' => $callback], new Environment([]));

    expect($config->get('callback'))->toBe($callback)
        ->and($config->get('missing', $callback))->toBe($callback);
});

it('caches a LazyValue once per Config context', function (): void {
    $calls = 0;
    $lazy = new LazyValue(function (Config $config) use (&$calls): string {
        ++$calls;
        return $config->string('name');
    });

    $first = new Config(
        ['name' => 'first', 'lazy' => $lazy],
        new Environment([]),
    );
    $second = new Config(
        ['name' => 'second', 'lazy' => $lazy],
        new Environment([]),
    );

    expect($first->string('lazy'))->toBe('first')
        ->and($first->string('lazy'))->toBe('first')
        ->and($second->string('lazy'))->toBe('second')
        ->and($calls)->toBe(2);
});

it('can disable LazyValue caching', function (): void {
    $calls = 0;
    $config = new Config([
        'lazy' => new LazyValue(function () use (&$calls): int {
            return ++$calls;
        }, cache: false),
    ], new Environment([]));

    expect($config->int('lazy'))->toBe(1)
        ->and($config->int('lazy'))->toBe(2);
});

it('fails fast on circular LazyValue resolution and clears the reentrancy guard', function (): void {
    $reenter = true;
    $lazy = null;
    $lazy = new LazyValue(function (Config $config) use (&$lazy, &$reenter): string {
        if ($reenter) {
            $reenter = false;

            if (!$lazy instanceof LazyValue) {
                throw new \LogicException('LazyValue was not initialized.');
            }

            $resolved = $lazy->resolve($config);

            if (!is_string($resolved)) {
                throw new \LogicException('Recursive LazyValue must resolve to a string.');
            }

            return $resolved;
        }

        return 'resolved';
    });
    $config = new Config(['lazy' => $lazy], new Environment([]));

    expect(fn() => $config->get('lazy'))
        ->toThrow(ConfigException::class, 'Circular LazyValue resolution')
        ->and($config->string('lazy'))->toBe('resolved');
});

it('filters literal nested and integer keys without mutating the original', function (): void {
    $config = new Config([
        0 => 'zero',
        'a' => 1,
        'database' => ['host' => 'localhost', 'secret' => 'hidden'],
    ], new Environment(['APP_ENV' => 'test']));

    $only = $config->only([0, 'a', new ConfigPath('database.host')]);
    $except = $config->except([0, new ConfigPath('database.secret')]);

    expect($only->toArray())->toBe([
        0 => 'zero',
        'a' => 1,
        'database' => ['host' => 'localhost'],
    ])->and($except->toArray())->toBe([
        'a' => 1,
        'database' => ['host' => 'localhost'],
    ])->and($config->get(new ConfigPath('database.secret')))->toBe('hidden')
        ->and($only->environment)->toBe($config->environment);
});

it('is read-only through ArrayAccess and supports integer offsets', function (): void {
    $config = new Config(
        [0 => 'zero', 'key' => 'value'],
        new Environment([]),
    );

    expect($config[0])->toBe('zero')
        ->and($config['key'])->toBe('value')
        ->and(isset($config[0]))->toBeTrue()
        ->and(isset($config['key']))->toBeTrue();

    expect(fn() => $config['key'] = 'changed')
        ->toThrow(RuntimeException::class, 'immutable')
        ->and(function () use ($config): void {
            unset($config['key']);
        })
        ->toThrow(RuntimeException::class, 'immutable');
});

it('rejects unsupported ArrayAccess offsets', function (): void {
    $config = new Config(['key' => 'value'], new Environment([]));
    $offsetGet = \Closure::fromCallable([$config, 'offsetGet']);

    expect(fn() => $offsetGet(new stdClass()))
        ->toThrow(InvalidConfigArgumentException::class, 'Config offset must be');
});

it('iterates and counts top-level entries', function (): void {
    $config = new Config(['a' => 1, 'b' => 2], new Environment([]));

    expect(iterator_to_array($config))->toBe(['a' => 1, 'b' => 2])
        ->and(count($config))->toBe(2)
        ->and($config->toArray())->toBe(['a' => 1, 'b' => 2]);
});
