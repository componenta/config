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

final class LazyValueStaticCallable
{
    public static function resolve(Config $config): string
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

it('preserves autoloadable method LazyValue callables through persistent cache', function (): void {
    $file = sys_get_temp_dir() . '/componenta_lazy_value_callable_' . bin2hex(random_bytes(6)) . '.php';

    try {
        $config = new Config([
            'static' => new LazyValue([LazyValueStaticCallable::class, 'resolve']),
            'object' => new LazyValue([new LazyValueObjectCallable(), 'resolve']),
        ], new Environment(['LAZY_VALUE_ENV' => 'build']));

        ConfigLoader::export($config, $file);

        $loaded = ConfigLoader::loadFromFile(
            $file,
            new Environment(['LAZY_VALUE_ENV' => 'runtime']),
        );

        expect($loaded->string('static'))->toBe('static:runtime')
            ->and($loaded->string('object'))->toBe('object:runtime');
    } finally {
        @unlink($file);
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
        @unlink($file);
    }
});
