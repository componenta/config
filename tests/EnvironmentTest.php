<?php

declare(strict_types=1);

use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigValueException;

it('reads literal and ConfigPath environment names', function (): void {
    $environment = new Environment([
        'APP_NAME' => 'Componenta',
        'DATABASE_HOST' => 'localhost',
    ]);

    expect($environment->string('APP_NAME'))->toBe('Componenta')
        ->and($environment->string(new ConfigPath('database.host')))->toBe('localhost');
});

it('rejects invalid environment snapshot keys', function (): void {
    expect(fn() => new Environment([0 => 'value']))
        ->toThrow(InvalidArgumentException::class, 'non-empty strings')
        ->and(fn() => new Environment(['' => 'value']))
        ->toThrow(InvalidArgumentException::class, 'non-empty strings');
});

it('supports defaults and required lookup', function (): void {
    $environment = new Environment([]);

    expect($environment->get('MISSING', 'fallback'))->toBe('fallback')
        ->and(fn() => $environment->get('MISSING'))
        ->toThrow(ConfigException::class);
});

it('provides typed accessors and rejects ambiguous booleans', function (): void {
    $environment = new Environment([
        'PORT' => '3306',
        'RATE' => '0.5',
        'ENABLED' => 'true',
        'HOSTS' => 'a, b',
        'INVALID' => 'maybe',
    ]);

    expect($environment->int('PORT'))->toBe(3306)
        ->and($environment->float('RATE'))->toBe(0.5)
        ->and($environment->bool('ENABLED'))->toBeTrue()
        ->and($environment->array('HOSTS'))->toBe(['a', 'b'])
        ->and(fn() => $environment->bool('INVALID'))
        ->toThrow(InvalidConfigValueException::class);
});

it('filters values by prefix', function (): void {
    $environment = new Environment([
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
        'APP_NAME' => 'Componenta',
    ]);

    expect($environment->prefix('DB_'))->toBe([
        'DB_HOST' => 'localhost',
        'DB_PORT' => '3306',
    ])->and($environment->prefix('DB_', removePrefix: true))->toBe([
        'HOST' => 'localhost',
        'PORT' => '3306',
    ]);
});

it('takes a deterministic snapshot of process server and env data', function (): void {
    $key = 'COMPONENTA_CONFIG_GLOBAL_' . bin2hex(random_bytes(4));

    putenv($key . '=native');
    $_SERVER[$key] = 'server';
    $_ENV[$key] = 'env';

    try {
        expect(Environment::fromGlobals([$key])->toArray())->toBe([$key => 'env']);
    } finally {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
});

it('supports collection helpers', function (): void {
    $environment = new Environment(['A' => '1', 'B' => '2']);

    expect($environment->keys())->toBe(['A', 'B'])
        ->and($environment->count())->toBe(2)
        ->and($environment->isEmpty())->toBeFalse()
        ->and(iterator_to_array($environment))->toBe(['A' => '1', 'B' => '2'])
        ->and($environment->toArray())->toBe(['A' => '1', 'B' => '2']);
});
