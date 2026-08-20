<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigLoader;
use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\LazyValue;

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

it('keeps application config behavior identical between provider and cache loading', function (): void {
    $file = configLoaderRuntime() . '/parity.php';
    $environment = new Environment([
        'APP_ENV' => 'production',
        'DATABASE_HOST' => 'runtime-db',
    ]);

    $providerConfig = ConfigLoader::load(
        $environment,
        static fn(): array => [
            'app' => ['name' => 'Componenta'],
            'database' => ['port' => '3306'],
        ],
    );

    ConfigLoader::export($providerConfig, $file);
    $cachedConfig = ConfigLoader::loadFromFile($file, $environment);

    expect($cachedConfig->toArray())->toBe($providerConfig->toArray())
        ->and($cachedConfig->environment)->toBe($providerConfig->environment)
        ->and($cachedConfig->string(new ConfigPath('app.name')))
        ->toBe($providerConfig->string(new ConfigPath('app.name')))
        ->and($cachedConfig->int(new ConfigPath('database.port')))
        ->toBe($providerConfig->int(new ConfigPath('database.port')))
        ->and($cachedConfig->environment->string('DATABASE_HOST'))->toBe('runtime-db');
});

it('keeps DI dependencies out of the application config cache', function (): void {
    $file = configLoaderRuntime() . '/without-dependencies.php';
    $config = new Config([
        'app' => ['name' => 'Componenta'],
        ConfigKey::DEPENDENCIES => [
            ConfigKey::SERVICES => ['runtime.object' => new stdClass()],
        ],
    ], new Environment(['APP_ENV' => 'build']));

    ConfigLoader::export($config, $file);
    $payload = require $file;
    $loaded = ConfigLoader::loadFromFile($file, new Environment(['APP_ENV' => 'runtime']));

    expect($payload)->toBe([
        'version' => ConfigLoader::CACHE_VERSION,
        'config' => ['app' => ['name' => 'Componenta']],
    ])->and($loaded->toArray())->toBe(['app' => ['name' => 'Componenta']])
        ->and($loaded->environment->string('APP_ENV'))->toBe('runtime');
});

it('exports LazyValue and captured closure values as self-contained persistent config', function (): void {
    $file = configLoaderRuntime() . '/lazy.php';
    $prefix = 'dsn:';
    $buildEnvironment = new Environment(['DATABASE_HOST' => 'build-db']);
    $config = new Config([
        'dsn' => new LazyValue(
            static fn($runtimeConfig): string => $prefix . $runtimeConfig->environment->string('DATABASE_HOST'),
        ),
        'uncached' => new LazyValue(
            static fn($runtimeConfig): string => $runtimeConfig->environment->string('DATABASE_HOST'),
            cache: false,
        ),
        'plain_callback' => static fn(): string => $prefix . 'plain',
    ], $buildEnvironment);

    ConfigLoader::export($config, $file);
    $loaded = ConfigLoader::loadFromFile(
        $file,
        new Environment(['DATABASE_HOST' => 'runtime-db']),
    );

    expect($loaded->string('dsn'))->toBe('dsn:runtime-db')
        ->and($loaded->get('uncached'))->toBeInstanceOf(LazyValue::class)
        ->and($loaded->get('uncached')->cache)->toBeFalse()
        ->and(($loaded->get('plain_callback'))())->toBe('dsn:plain');
});

it('never serializes build-time environment values and secures cache permissions', function (): void {
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
    ])->and($loaded->environment)->toBe($runtimeEnvironment)
        ->and($loaded->environment->string('DATABASE_PASSWORD'))->toBe('runtime-secret');

    if (PHP_OS_FAMILY !== 'Windows') {
        clearstatcache(true, $file);
        expect(fileperms($file) & 0777)->toBe(0600);
    }
});

it('can replace an existing cache snapshot', function (): void {
    $file = configLoaderRuntime() . '/replace.php';
    $environment = new Environment([]);

    ConfigLoader::export(new Config(['version' => 1]), $file);
    ConfigLoader::export(new Config(['version' => 2]), $file);

    expect(ConfigLoader::loadFromFile($file, $environment)->int('version'))->toBe(2);
});

it('rejects unsupported cache envelopes versions and embedded DI roots', function (): void {
    $environment = new Environment([]);

    $missing = configLoaderRuntime() . '/missing.php';
    expect(fn() => ConfigLoader::loadFromFile($missing, $environment))
        ->toThrow(ConfigException::class, 'not readable');

    $unsupported = configLoaderRuntime() . '/unsupported.php';
    file_put_contents(
        $unsupported,
        "<?php return ['version' => " . ConfigLoader::CACHE_VERSION
        . ", 'config' => [], 'metadata' => []];",
    );
    expect(fn() => ConfigLoader::loadFromFile($unsupported, $environment))
        ->toThrow(ConfigException::class, 'Unsupported configuration cache envelope key');

    $stale = configLoaderRuntime() . '/stale.php';
    file_put_contents($stale, "<?php return ['version' => 1, 'config' => []];");
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

    $diRoot = configLoaderRuntime() . '/di-root.php';
    file_put_contents(
        $diRoot,
        '<?php return ['
        . "'version' => " . ConfigLoader::CACHE_VERSION
        . ", 'config' => ['dependencies' => []]];",
    );
    expect(fn() => ConfigLoader::loadFromFile($diRoot, $environment))
        ->toThrow(ConfigException::class, 'must not contain reserved DI root');
});
