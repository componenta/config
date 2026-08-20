<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;

function configLoaderRuntime(): string
{
    static $path;
    return $path ??= sys_get_temp_dir() . '/componenta_config_loader_' . bin2hex(random_bytes(5));
}

function removeConfigLoaderRuntime(): void
{
    $root = configLoaderRuntime();
    if (!is_dir($root)) { return; }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($root);
}

beforeEach(function (): void { @mkdir(configLoaderRuntime(), 0700, true); });
afterEach(function (): void { removeConfigLoaderRuntime(); });

it('loads and recursively composes providers', function (): void {
    $environment = new Environment(['APP_ENV' => 'test']);
    $config = ConfigLoader::load(
        $environment,
        static fn(): array => ['database' => ['host' => 'localhost']],
        static fn(): array => ['database' => ['port' => 3306]],
    );

    expect($config->get(new ConfigPath('database.host')))->toBe('localhost')
        ->and($config->get(new ConfigPath('database.port')))->toBe(3306)
        ->and($config->environment)->toBe($environment);
});

it('accepts iterable provider output', function (): void {
    $config = ConfigLoader::load(
        null,
        static fn(): Traversable => new ArrayIterator(['value' => 42]),
    );

    expect($config->int('value'))->toBe(42);
});

it('rejects invalid provider output', function (): void {
    expect(fn() => ConfigLoader::load(null, static fn(): string => 'invalid'))
        ->toThrow(ConfigException::class, 'array or iterable');
});

it('exports and reloads an atomic PHP cache snapshot', function (): void {
    $file = configLoaderRuntime() . '/nested/config.php';
    $original = new Config(
        ['app' => ['name' => 'Componenta']],
        new Environment(['APP_ENV' => 'production']),
    );

    ConfigLoader::export($original, $file);
    $loaded = ConfigLoader::loadFromFile($file);

    expect($loaded->toArray())->toBe($original->toArray())
        ->and($loaded->environment?->toArray())->toBe(['APP_ENV' => 'production']);
});

it('can replace an existing cache snapshot', function (): void {
    $file = configLoaderRuntime() . '/replace.php';

    ConfigLoader::export(new Config(['version' => 1]), $file);
    ConfigLoader::export(new Config(['version' => 2]), $file);

    expect(ConfigLoader::loadFromFile($file)->int('version'))->toBe(2);
});

it('can populate environment globals from a cache', function (): void {
    $file = configLoaderRuntime() . '/config.php';
    unset($_ENV['CONFIG_LOADER_ENV'], $_SERVER['CONFIG_LOADER_ENV']);

    ConfigLoader::export(
        new Config([], new Environment(['CONFIG_LOADER_ENV' => 'cached'])),
        $file,
    );

    ConfigLoader::loadFromFile($file, populateEnv: true);

    expect($_ENV['CONFIG_LOADER_ENV'])->toBe('cached')
        ->and($_SERVER['CONFIG_LOADER_ENV'])->toBe('cached');

    unset($_ENV['CONFIG_LOADER_ENV'], $_SERVER['CONFIG_LOADER_ENV']);
});

it('rejects missing and malformed cache files', function (): void {
    expect(fn() => ConfigLoader::loadFromFile(configLoaderRuntime() . '/missing.php'))
        ->toThrow(ConfigException::class, 'not readable');

    $invalid = configLoaderRuntime() . '/invalid.php';
    file_put_contents($invalid, "<?php return ['config' => 'not-an-array'];");

    expect(fn() => ConfigLoader::loadFromFile($invalid))
        ->toThrow(ConfigException::class, '"config" entry must be an array');
});
