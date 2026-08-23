<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\LazyValue;

use function Componenta\Config\config_entry;
use function Componenta\Config\lazy;

it('persists LazyValue nested inside ConfigEntry default through the root dispatcher', function (): void {
    $file = sys_get_temp_dir() . '/componenta_nested_lazy_' . bin2hex(random_bytes(6)) . '.php';
    try {
        $config = new Config([
            'value' => 'runtime-value',
            'entry' => config_entry('missing', lazy(static fn(Config $config): string => $config->string('value'))),
        ], new Environment([]));
        ConfigLoader::export($config, $file);
        $loaded = ConfigLoader::loadFromFile($file, new Environment([]));
        $entry = $loaded->get('entry');
        expect($entry)->toBeInstanceOf(ConfigEntry::class)->and($entry->default)->toBeInstanceOf(LazyValue::class)->and($entry->resolve($loaded))->toBe('runtime-value');
    } finally { @unlink($file); }
});

it('rejects arbitrary generic readonly objects from persistent config', function (): void {
    $file = sys_get_temp_dir() . '/componenta_generic_readonly_' . bin2hex(random_bytes(6)) . '.php';
    $object = new readonly class ('value') { public function __construct(public string $value) {} };
    try { expect(fn() => ConfigLoader::export(new Config(['object' => $object], new Environment([])), $file))->toThrow(ConfigException::class, 'Generic readonly-object export is disabled'); expect(is_file($file))->toBeFalse(); }
    finally { @unlink($file); }
});

it('rejects source-root-dependent closures from portable config artifacts', function (): void {
    $file = sys_get_temp_dir() . '/componenta_source_path_' . bin2hex(random_bytes(6)) . '.php'; $closure = static fn(): string => __FILE__;
    try { expect(fn() => ConfigLoader::export(new Config(['callback' => $closure], new Environment([])), $file))->toThrow(ConfigException::class, '__FILE__'); expect(is_file($file))->toBeFalse(); }
    finally { @unlink($file); }
});

it('rejects namespace-fallback function calls from portable config artifacts', function (): void {
    $cache = sys_get_temp_dir() . '/componenta_namespace_fallback_cache_' . bin2hex(random_bytes(6)) . '.php';
    $source = sys_get_temp_dir() . '/componenta_namespace_fallback_source_' . bin2hex(random_bytes(6)) . '.php';
    try {
        file_put_contents($source, "<?php\nnamespace Componenta\\Config\\Tests\\PortableFixture;\nreturn static fn(): int => strlen('abc');\n");
        $closure = require $source;
        expect(fn() => ConfigLoader::export(new Config(['callback' => $closure], new Environment([])), $cache))->toThrow(ConfigException::class, 'unqualified function');
        expect(is_file($cache))->toBeFalse();
    } finally { @unlink($cache); @unlink($source); }
});

it('reports the nested config value path when a special default is not portable', function (): void {
    $file = sys_get_temp_dir() . '/componenta_nested_path_' . bin2hex(random_bytes(6)) . '.php'; $sourceBound = static fn(): string => __FILE__;
    try {
        $config = new Config(['entry' => config_entry('missing', lazy($sourceBound))], new Environment([]));
        expect(fn() => ConfigLoader::export($config, $file))->toThrow(ConfigException::class, "['default']");
        expect(is_file($file))->toBeFalse();
    } finally { @unlink($file); }
});
