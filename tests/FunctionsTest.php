<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerEntry;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\LazyValue;
use Psr\Container\ContainerInterface;

use function Componenta\Config\config;
use function Componenta\Config\config_entry;
use function Componenta\Config\config_merge;
use function Componenta\Config\entry;
use function Componenta\Config\env;
use function Componenta\Config\lazy;
use function Componenta\Config\path;

it('creates helper value objects including runtime environment references', function (): void {
    expect(path('database.host'))->toBeInstanceOf(ConfigPath::class)
        ->and(entry('service'))->toBeInstanceOf(ContainerEntry::class)
        ->and(config_entry('fallback'))->toBeInstanceOf(ConfigEntry::class)
        ->and(config_entry(0)->key)->toBe(0)
        ->and(env('APP_NAME'))->toBeInstanceOf(EnvironmentEntry::class)
        ->and(env(path('database.host'))->key)->toBeInstanceOf(ConfigPath::class)
        ->and(lazy(static fn(): int => 1))->toBeInstanceOf(LazyValue::class);
});

it('resolves Config from a PSR container and validates its type', function (): void {
    $expected = new Config(['app' => 'test']);
    $container = new class ($expected) implements ContainerInterface {
        public function __construct(private readonly mixed $value) {}

        public function get(string $id): mixed
        {
            return $this->value;
        }

        public function has(string $id): bool
        {
            return true;
        }
    };

    expect(config($container))->toBe($expected);

    $invalid = new class implements ContainerInterface {
        public function get(string $id): mixed
        {
            return new stdClass();
        }

        public function has(string $id): bool
        {
            return true;
        }
    };

    expect(fn() => config($invalid))->toThrow(ConfigException::class, 'must be an instance');
});

it('resolves environment entries only from the Config runtime snapshot', function (): void {
    $data = [
        'name' => env('APP_NAME'),
        'port' => env('PORT', '8080'),
        'debug' => env('APP_DEBUG', 'false'),
        'database-host' => env(path('database.host')),
    ];

    $first = new Config($data, new Environment([
        'APP_NAME' => 'first',
        'PORT' => '3306',
        'APP_DEBUG' => 'true',
        'DATABASE_HOST' => 'db-a',
    ]));
    $second = new Config($data, new Environment([
        'APP_NAME' => 'second',
        'APP_DEBUG' => 'false',
        'DATABASE_HOST' => 'db-b',
    ]));

    expect($first->string('name'))->toBe('first')
        ->and($first->int('port'))->toBe(3306)
        ->and($first->bool('debug'))->toBeTrue()
        ->and($first->string('database-host'))->toBe('db-a')
        ->and($second->string('name'))->toBe('second')
        ->and($second->int('port'))->toBe(8080)
        ->and($second->bool('debug'))->toBeFalse()
        ->and($second->string('database-host'))->toBe('db-b');
});

it('fails when a required runtime environment entry is missing', function (): void {
    $config = new Config(['required' => env('REQUIRED_ENV')], new Environment([]));

    expect(fn() => $config->get('required'))->toThrow(ConfigException::class, 'REQUIRED_ENV')
        ->and(fn() => env(''))->toThrow(InvalidArgumentException::class);
});

it('merges generic configuration recursively and appends lists', function (): void {
    expect(config_merge(
        ['middleware' => ['auth'], 'database' => ['host' => 'localhost']],
        ['middleware' => ['csrf'], 'database' => ['port' => 3306]],
    ))->toBe([
        'middleware' => ['auth', 'csrf'],
        'database' => ['host' => 'localhost', 'port' => 3306],
    ]);
});
