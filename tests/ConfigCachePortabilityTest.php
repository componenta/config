<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;

final class PlainScopedConfigClosureFixture
{
    private const string VALUE = 'scoped';

    public static function callback(): Closure
    {
        return static fn(): string => self::VALUE;
    }
}

it('rejects class-scoped plain closures before writing a semantically different cache', function (): void {
    $file = sys_get_temp_dir() . '/componenta_plain_scoped_closure_' . bin2hex(random_bytes(6)) . '.php';

    try {
        $config = new Config([
            'callback' => PlainScopedConfigClosureFixture::callback(),
        ], new Environment([]));

        expect(fn() => ConfigLoader::export($config, $file))
            ->toThrow(ConfigException::class, 'class-scoped plain Closure');
        expect(is_file($file))->toBeFalse();
    } finally {
        @unlink($file);
    }
});

it('rejects cache graphs deeper than the exporter traversal limit', function (): void {
    $file = sys_get_temp_dir() . '/componenta_deep_config_' . bin2hex(random_bytes(6)) . '.php';
    $data = ['value' => true];

    for ($i = 0; $i < 66; ++$i) {
        $data = ['nested' => $data];
    }

    try {
        expect(fn() => ConfigLoader::export(new Config($data, new Environment([])), $file))
            ->toThrow(ConfigException::class, 'maximum nesting depth');
        expect(is_file($file))->toBeFalse();
    } finally {
        @unlink($file);
    }
});
