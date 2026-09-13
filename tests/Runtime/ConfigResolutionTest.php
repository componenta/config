<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\Exception\ConfigResolutionException;
use Componenta\Config\LazyValue;

it('recursively resolves descriptors throughout a selected array subtree', function (): void {
    $calls = 0;
    $environment = new Environment(['DB_HOST' => 'localhost']);
    $shared = new LazyValue(static function () use (&$calls): string {
        ++$calls;

        return 'shared';
    });
    $config = new Config([
        'port' => 3306,
        'connection' => [
            'host' => new EnvironmentEntry('DB_HOST'),
            'options' => [
                'port' => new ConfigEntry('port'),
                'name' => new LazyValue(
                    static fn(): ConfigEntry => new ConfigEntry('port'),
                ),
            ],
            4 => $shared,
            7 => $shared,
        ],
    ], $environment);

    expect($config->get('connection'))->toBe([
        'host' => 'localhost',
        'options' => [
            'port' => 3306,
            'name' => 3306,
        ],
        4 => 'shared',
        7 => 'shared',
    ])->and($calls)->toBe(1);
});

it('recursively resolves descriptor results in the same runtime config context', function (): void {
    $environment = new Environment(['APP_NAME' => 'runtime']);
    $config = new Config([
        'environment_name' => new EnvironmentEntry('APP_NAME'),
        'config_name' => new ConfigEntry('environment_name'),
        'lazy_name' => new LazyValue(
            static fn(): ConfigEntry => new ConfigEntry('config_name'),
        ),
    ], $environment);

    expect($config->string('lazy_name'))->toBe('runtime');
});

it('reports a ConfigEntry cycle with its resolution path', function (): void {
    $config = new Config([
        'first' => new ConfigEntry('second'),
        'second' => new ConfigEntry('first'),
    ], new Environment([]));

    expect(fn() => $config->get('first'))->toThrow(
        ConfigResolutionException::class,
        'first -> second -> first',
    );
});

it('reports recursive array references through the typed resolution contract', function (): void {
    $recursive = [];
    $recursive['self'] = &$recursive;
    $config = new Config(['recursive' => $recursive], new Environment([]));

    expect(fn() => $config->get('recursive'))->toThrow(
        ConfigResolutionException::class,
        'recursive.self.self',
    );
});

it('limits descriptor chains without rejecting the supported boundary', function (): void {
    $chain = static function (int $length): mixed {
        $value = 'resolved';

        for ($index = 0; $index < $length; ++$index) {
            $next = $value;
            $value = new LazyValue(static fn(): mixed => $next, cache: false);
        }

        return $value;
    };
    $config = new Config([
        'boundary' => $chain(64),
        'too_deep' => $chain(65),
    ], new Environment([]));

    expect($config->get('boundary'))->toBe('resolved')
        ->and(fn() => $config->get('too_deep'))->toThrow(
            ConfigResolutionException::class,
            'maximum depth of 64',
        );
});

it('enforces the array resolution depth boundary without rejecting the deepest allowed value', function (): void {
    $value = 'leaf';
    for ($depth = 0; $depth < 64; ++$depth) {
        $value = ['node' => $value];
    }
    $config = new Config(['allowed' => $value, 'too-deep' => ['node' => $value]], new Environment([]));

    expect($config->get('allowed'))->toBe($value)
        ->and(fn() => $config->get('too-deep'))->toThrow(ConfigResolutionException::class, '64');
});
