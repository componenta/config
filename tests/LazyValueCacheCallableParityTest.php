<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\LazyValue;

function lazyValueNamedCallable(Config $config): string
{
    return 'named:' . $config->environment->string('LAZY_VALUE_ENV');
}

function removeLazyValueCacheFile(string $file): void
{
    if (is_file($file)) {
        unlink($file);
    }
}

class LazyValueStaticBaseCallable
{
    public static function resolve(Config $config): string
    {
        return static::class . ':' . $config->environment->string('LAZY_VALUE_ENV');
    }
}

final class LazyValueStaticCallable extends LazyValueStaticBaseCallable
{
    public static function direct(Config $config): string
    {
        return 'static:' . $config->environment->string('LAZY_VALUE_ENV');
    }
}

final readonly class LazyValueObjectCallable
{
    public function resolve(Config $config): string
    {
        return 'object:' . $config->environment->string('LAZY_VALUE_ENV');
    }
}

final class LazyValueScopedClosure
{
    private const string PREFIX = 'scoped';

    public static function make(): LazyValue
    {
        return new LazyValue(
            static fn(Config $config): string => self::PREFIX
                . ':' . static::class
                . ':' . $config->environment->string('LAZY_VALUE_ENV'),
        );
    }
}

class LazyValueScopedClosureBase
{
    private const string PREFIX = 'base';

    public static function make(): LazyValue
    {
        return new LazyValue(
            static fn(Config $config): string => self::PREFIX
                . ':' . static::class
                . ':' . $config->environment->string('LAZY_VALUE_ENV'),
        );
    }
}

final class LazyValueScopedClosureChild extends LazyValueScopedClosureBase {}

it('preserves autoloadable method LazyValue callables through persistent cache', function (): void {
    $file = sys_get_temp_dir() . '/componenta_lazy_value_callable_' . bin2hex(random_bytes(6)) . '.php';

    try {
        $config = new Config([
            'static' => new LazyValue([LazyValueStaticCallable::class, 'direct']),
            'inherited-static' => new LazyValue([LazyValueStaticCallable::class, 'resolve']),
            'object' => new LazyValue([new LazyValueObjectCallable(), 'resolve']),
        ], new Environment(['LAZY_VALUE_ENV' => 'build']));

        ConfigLoader::export($config, $file);

        $loaded = ConfigLoader::loadFromFile(
            $file,
            new Environment(['LAZY_VALUE_ENV' => 'runtime']),
        );

        expect($loaded->string('static'))->toBe('static:runtime')
            ->and($loaded->string('inherited-static'))
            ->toBe(LazyValueStaticCallable::class . ':runtime')
            ->and($loaded->string('object'))->toBe('object:runtime');
    } finally {
        removeLazyValueCacheFile($file);
    }
});

it('preserves same-class lexical scope for anonymous LazyValue closures', function (): void {
    $file = sys_get_temp_dir() . '/componenta_lazy_value_scope_' . bin2hex(random_bytes(6)) . '.php';

    try {
        $config = new Config([
            'scoped' => LazyValueScopedClosure::make(),
        ], new Environment(['LAZY_VALUE_ENV' => 'build']));

        ConfigLoader::export($config, $file);
        $loaded = ConfigLoader::loadFromFile(
            $file,
            new Environment(['LAZY_VALUE_ENV' => 'runtime']),
        );

        expect($loaded->string('scoped'))
            ->toBe('scoped:' . LazyValueScopedClosure::class . ':runtime');
    } finally {
        removeLazyValueCacheFile($file);
    }
});

it('rejects anonymous LazyValue closures whose lexical and called classes differ', function (): void {
    $file = sys_get_temp_dir() . '/componenta_lazy_value_inherited_scope_' . bin2hex(random_bytes(6)) . '.php';

    try {
        $config = new Config([
            'scoped' => LazyValueScopedClosureChild::make(),
        ], new Environment(['LAZY_VALUE_ENV' => 'build']));

        expect(fn() => ConfigLoader::export($config, $file))
            ->toThrow(ConfigException::class, 'late-static-binding state cannot be reconstructed exactly');
        expect(is_file($file))->toBeFalse();
    } finally {
        removeLazyValueCacheFile($file);
    }
});

it('rejects user-defined named function LazyValue callbacks before writing a non-portable cache', function (): void {
    $file = sys_get_temp_dir() . '/componenta_lazy_value_named_' . bin2hex(random_bytes(6)) . '.php';

    try {
        $config = new Config([
            'named' => new LazyValue('lazyValueNamedCallable'),
        ], new Environment(['LAZY_VALUE_ENV' => 'build']));

        expect(fn() => ConfigLoader::export($config, $file))
            ->toThrow(ConfigException::class, 'user-defined named function');
        expect(is_file($file))->toBeFalse();
    } finally {
        removeLazyValueCacheFile($file);
    }
});

it('rejects shared bound object targets across LazyValue callbacks', function (): void {
    $file = sys_get_temp_dir() . '/componenta_lazy_value_shared_target_' . bin2hex(random_bytes(6)) . '.php';
    $target = new LazyValueObjectCallable();

    try {
        $config = new Config([
            'first' => new LazyValue([$target, 'resolve']),
            'second' => new LazyValue([$target, 'resolve']),
        ], new Environment(['LAZY_VALUE_ENV' => 'build']));

        expect(fn() => ConfigLoader::export($config, $file))
            ->toThrow(ConfigException::class, 'shared object identity');
        expect(is_file($file))->toBeFalse();
    } finally {
        removeLazyValueCacheFile($file);
    }
});
