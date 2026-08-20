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
    if (!is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($root);
}

beforeEach(function (): void {
    @mkdir(configLoaderRuntime(), 0700, true);
});

afterEach(function (): void {
    removeConfigLoaderRuntime();
});

it('loads and recursively composes providers into one runtime snapshot', function (): void {
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
    $environment = new Environment([]);
    $config = ConfigLoader::load(
        $environment,
        static fn(): Traversable => new ArrayIterator(['value' => 42]),
    );

    expect($config->int('value'))->toBe(42)
        ->and($config->environment)->toBe($environment);
});

it('rejects invalid provider output', function (): void {
    expect(fn() => ConfigLoader::load(
        new Environment([]),
        static fn(): string => 'invalid',
    ))->toThrow(ConfigException::class, 'array or iterable');
});

it('exports only persistent config data and binds runtime environment on load', function (): void {
    $file = configLoaderRuntime() . '/nested/config.php';
    $buildEnvironment = new Environment([
        'APP_ENV' => 'build',
        'DATABASE_PASSWORD' => 'build-secret',
    ]);
    $original = new Config(
        ['app' => ['name' => 'Componenta']],
        $buildEnvironment,
    );

    ConfigLoader::export($original, $file);

    $payload = require $file;
    $runtimeEnvironment = new Environment([
        'APP_ENV' => 'production',
        'DATABASE_PASSWORD' => 'runtime-secret',
    ]);
    $loaded = ConfigLoader::loadFromFile($file, $runtimeEnvironment);

    expect($payload)->toBe([
        'version' => ConfigLoader::CACHE_VERSION,
        'config' => $original->toArray(),
    ])->and($payload)->not->toHaveKey('environment')
        ->and($loaded->toArray())->toBe($original->toArray())
        ->and($loaded->environment)->toBe($runtimeEnvironment)
        ->and($loaded->environment->string('DATABASE_PASSWORD'))->toBe('runtime-secret');
});

it('can replace an existing cache snapshot', function (): void {
    $file = configLoaderRuntime() . '/replace.php';
    $environment = new Environment([]);

    ConfigLoader::export(new Config(['version' => 1]), $file);
    ConfigLoader::export(new Config(['version' => 2]), $file);

    expect(ConfigLoader::loadFromFile($file, $environment)->int('version'))->toBe(2);
});

it('rejects stale legacy and malformed cache envelopes', function (): void {
    $environment = new Environment([]);

    $missing = configLoaderRuntime() . '/missing.php';
    expect(fn() => ConfigLoader::loadFromFile($missing, $environment))
        ->toThrow(ConfigException::class, 'not readable');

    $legacy = configLoaderRuntime() . '/legacy.php';
    file_put_contents($legacy, "<?php return ['config' => [], 'environment' => []];");
    expect(fn() => ConfigLoader::loadFromFile($legacy, $environment))
        ->toThrow(ConfigException::class, 'Unsupported configuration cache envelope key');

    $stale = configLoaderRuntime() . '/stale.php';
    file_put_contents($stale, "<?php return ['version' => 0, 'config' => []];");
    expect(fn() => ConfigLoader::loadFromFile($stale, $environment))
        ->toThrow(ConfigException::class, 'Unsupported configuration cache version');

    $invalid = configLoaderRuntime() . '/invalid.php';
    file_put_contents(
        $invalid,
        '<?php return ['
        . "'version' => " . ConfigLoader::CACHE_VERSION . ", 'config' => 'not-an-array'];",
    );
    expect(fn() => ConfigLoader::loadFromFile($invalid, $environment))
        ->toThrow(ConfigException::class, '"config" entry must be an array');
});
