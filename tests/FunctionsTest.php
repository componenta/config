<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerEntry;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\LazyValue;
use Psr\Container\ContainerInterface;

use function Componenta\Config\config;
use function Componenta\Config\config_entry;
use function Componenta\Config\config_merge;
use function Componenta\Config\entry;
use function Componenta\Config\env;
use function Componenta\Config\env_array;
use function Componenta\Config\env_bool;
use function Componenta\Config\env_float;
use function Componenta\Config\env_int;
use function Componenta\Config\env_string;
use function Componenta\Config\lazy;
use function Componenta\Config\path;

function withTemporaryEnv(string $key, mixed $value, Closure $test): void
{
    $hadEnv = array_key_exists($key, $_ENV);
    $oldEnv = $_ENV[$key] ?? null;
    $hadServer = array_key_exists($key, $_SERVER);
    $oldServer = $_SERVER[$key] ?? null;
    $oldNative = getenv($key);

    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);

    if ($value !== null) {
        $_ENV[$key] = $value;
    }

    try {
        $test();
    } finally {
        if ($hadEnv) {
            $_ENV[$key] = $oldEnv;
        } else {
            unset($_ENV[$key]);
        }

        if ($hadServer) {
            $_SERVER[$key] = $oldServer;
        } else {
            unset($_SERVER[$key]);
        }

        if ($oldNative === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $oldNative);
        }
    }
}

it('creates helper value objects including integer config references', function (): void {
    expect(path('database.host'))->toBeInstanceOf(ConfigPath::class)
        ->and(entry('service'))->toBeInstanceOf(ContainerEntry::class)
        ->and(config_entry('fallback'))->toBeInstanceOf(ConfigEntry::class)
        ->and(config_entry(0)->key)->toBe(0)
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

it('returns raw environment values while typed helpers convert explicitly', function (): void {
    withTemporaryEnv('COMPONENTA_CONFIG_NUMBER', '3306', function (): void {
        expect(env('COMPONENTA_CONFIG_NUMBER'))->toBe('3306')
            ->and(env_string('COMPONENTA_CONFIG_NUMBER'))->toBe('3306')
            ->and(env_int('COMPONENTA_CONFIG_NUMBER'))->toBe(3306);
    });

    withTemporaryEnv('COMPONENTA_CONFIG_FLOAT', '1e3', function (): void {
        expect(env('COMPONENTA_CONFIG_FLOAT'))->toBe('1e3')
            ->and(env_float('COMPONENTA_CONFIG_FLOAT'))->toBe(1000.0)
            ->and(fn() => env_int('COMPONENTA_CONFIG_FLOAT'))
            ->toThrow(ConfigException::class, 'to int');
    });

    withTemporaryEnv('COMPONENTA_CONFIG_FALSE_STRING', 'false', function (): void {
        expect(env('COMPONENTA_CONFIG_FALSE_STRING'))->toBe('false')
            ->and(env_string('COMPONENTA_CONFIG_FALSE_STRING'))->toBe('false')
            ->and(env_bool('COMPONENTA_CONFIG_FALSE_STRING'))->toBeFalse();
    });

    withTemporaryEnv('COMPONENTA_CONFIG_ARRAY', 'redis, file', function (): void {
        expect(env_array('COMPONENTA_CONFIG_ARRAY'))->toBe(['redis', 'file']);
    });
});

it('preserves lexical strings and rejects lossy integer conversion', function (): void {
    withTemporaryEnv('COMPONENTA_CONFIG_PADDED', '001', function (): void {
        expect(env_string('COMPONENTA_CONFIG_PADDED'))->toBe('001')
            ->and(env_int('COMPONENTA_CONFIG_PADDED'))->toBe(1);
    });

    withTemporaryEnv('COMPONENTA_CONFIG_FRACTIONAL', '3.9', function (): void {
        expect(env_string('COMPONENTA_CONFIG_FRACTIONAL'))->toBe('3.9')
            ->and(fn() => env_int('COMPONENTA_CONFIG_FRACTIONAL'))
            ->toThrow(ConfigException::class, 'to int');
    });
});

it('does not silently stringify unsupported environment values', function (): void {
    withTemporaryEnv('COMPONENTA_CONFIG_ARRAY_VALUE', ['not', 'stringable'], function (): void {
        expect(fn() => env_string('COMPONENTA_CONFIG_ARRAY_VALUE'))
            ->toThrow(ConfigException::class, 'to string');
    });
});

it('uses defaults and rejects missing required environment values', function (): void {
    withTemporaryEnv('COMPONENTA_CONFIG_MISSING', null, function (): void {
        expect(env('COMPONENTA_CONFIG_MISSING', 'fallback'))->toBe('fallback')
            ->and(env_int('COMPONENTA_CONFIG_MISSING', 42))->toBe(42)
            ->and(fn() => env('COMPONENTA_CONFIG_MISSING'))
            ->toThrow(ConfigException::class);
    });
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
