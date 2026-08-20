# Componenta Config

`componenta/config` is a small configuration library for PHP 8.4+. It provides immutable configuration snapshots, explicit nested paths, typed reads, environment loading, file providers, deterministic configuration composition, lazy values and PHP cache generation.

The package does not depend on a framework. It can be used on its own or as the configuration layer for a dependency-injection container.

## Installation

```bash
composer require componenta/config
```

## Quick start

Create a configuration snapshot from an array:

```php
use Componenta\Config\Config;
use function Componenta\Config\path;

$config = new Config([
    'app' => [
        'name' => 'Example',
        'debug' => false,
    ],
]);

$name = $config->string(path('app.name'));
$debug = $config->bool(path('app.debug'));
```

A plain string is always a **literal key**. Use `ConfigPath` when you want nested lookup:

```php
use Componenta\Config\Config;
use function Componenta\Config\path;

$config = new Config([
    'database.host' => 'literal-key',
    'database' => [
        'host' => 'localhost',
    ],
]);

$config->get('database.host');       // literal-key
$config->get(path('database.host')); // localhost
```

This distinction avoids ambiguity when configuration keys themselves contain dots.

## Typed values

`Config` has typed accessors for common scalar configuration:

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$ratio = $config->float('ratio', 1.0);
$enabled = $config->bool('enabled', false);
$drivers = $config->array('drivers', []);
```

Invalid conversions fail with `InvalidConfigValueException` instead of silently producing an unrelated value.

`get()` returns the stored value as-is:

```php
$value = $config->get('key');
$value = $config->get('optional', 'fallback');
```

If a key is required, omit the default. A missing key then raises `ConfigException`.

## Paths

Create paths directly or with the `path()` helper:

```php
use Componenta\Config\ConfigPath;
use function Componenta\Config\path;

$path = new ConfigPath('database.connections.primary');
$path = path('database.connections.primary');
```

Useful path methods:

```php
$path->toArray();  // ['database', 'connections', 'primary']
$path->first();    // database
$path->last();     // primary
$path->isNested(); // true
```

## Defaults and references

A normal default is returned when the requested key is absent:

```php
$timeout = $config->int('timeout', 30);
```

Use `ConfigEntry` when the fallback should come from another configuration key:

```php
use function Componenta\Config\config_entry;
use function Componenta\Config\path;

$name = $config->string(
    'display_name',
    config_entry(path('app.name')),
);
```

## Lazy values

Plain callables are ordinary configuration values and are never executed automatically.

For explicit lazy evaluation, wrap the callback with `lazy()`:

```php
use Componenta\Config\Config;
use function Componenta\Config\lazy;
use function Componenta\Config\path;

$config = new Config([
    'host' => 'localhost',
    'dsn' => lazy(
        static fn (Config $config): string =>
            'mysql:host=' . $config->string('host'),
    ),
]);

$dsn = $config->string('dsn');
```

Lazy results are cached by default **per `Config` or `ContainerValue` context**. Reusing the same lazy wrapper in another configuration snapshot cannot leak the result from the first snapshot.

Disable caching when a value must be recomputed:

```php
$value = lazy(
    static fn (): int => random_int(1, 100),
    cache: false,
);
```

## Filtering configuration

`Config` is immutable. `only()` and `except()` return new snapshots:

```php
$public = $config->only([
    'app',
    path('database.host'),
]);

$withoutSecrets = $config->except([
    path('database.password'),
]);
```

The original instance is unchanged.

`Config` also implements `Countable`, `IteratorAggregate`, `ArrayAccess` for reads, and `Componenta\Arrayable\Arrayable`.

## Environment values

### Environment snapshot

`Environment` is an immutable environment snapshot:

```php
use Componenta\Config\Environment;

$environment = new Environment([
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
]);

$mode = $environment->string('APP_ENV');
$debug = $environment->bool('APP_DEBUG');
```

You can snapshot the current process, `$_SERVER` and `$_ENV`:

```php
$environment = Environment::fromGlobals();
```

Precedence is deterministic:

```text
process environment < $_SERVER < $_ENV
```

A `ConfigPath` is converted to upper snake case:

```php
$environment->string(path('database.host')); // DATABASE_HOST
```

### Loading `.env` files

`EnvLoader` loads `.env` and `.env.local` by default, in that order:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load();
```

`.env.local` can override values from `.env`.

Existing deployment/process values remain authoritative unless you explicitly enable override:

```php
$environment = (new EnvLoader('.'))->load(override: true);
```

Sample, backup and other `.env*` files are **not** discovered implicitly. If you need different files, name them explicitly:

```php
$loader = new EnvLoader(
    '.',
    filenames: ['.env', '.env.production'],
);
```

Required variables may come either from loaded files or from the deployment environment:

```php
$loader = new EnvLoader(
    '.',
    required: ['APP_KEY', 'DATABASE_URL'],
);
```

`read()` only parses files and does not mutate `$_ENV` or `$_SERVER`.

### Environment helper functions

For small bootstrap files you can use:

```php
use function Componenta\Config\env;
use function Componenta\Config\env_array;
use function Componenta\Config\env_bool;
use function Componenta\Config\env_float;
use function Componenta\Config\env_int;
use function Componenta\Config\env_string;

$name = env_string('APP_NAME', 'Example');
$port = env_int('PORT', 8080);
$debug = env_bool('APP_DEBUG', false);
$ratio = env_float('RATIO', 1.0);
$hosts = env_array('HOSTS', []);
$value = env('CUSTOM_VALUE');
```

Typed helpers convert the raw environment value directly and reject values they cannot safely convert. The generic `env()` helper is the only helper that infers booleans and numeric types automatically.

## Loading configuration from files

`FileProvider` supports PHP and JSON by default:

```php
use Componenta\Config\FileProvider;

$provider = new FileProvider('config/*.{php,json}');
$data = $provider();
```

Files are processed in sorted path order and merged with `config_merge()`.

PHP configuration files must return an array:

```php
<?php

return [
    'app' => [
        'name' => 'Example',
    ],
];
```

JSON files must contain an object or array at the root.

Matched files are treated as configuration inputs. An unreadable file, malformed file, unsupported matched extension or invalid root value fails fast with `ConfigException`.

### Custom file readers

Implement `FileReaderInterface`:

```php
use Componenta\Config\Reader\FileReaderInterface;

final class IniReader implements FileReaderInterface
{
    public function readFile(string $file): ?array
    {
        if (!str_ends_with($file, '.ini')) {
            return null;
        }

        $data = parse_ini_file($file, true);

        if ($data === false) {
            throw new RuntimeException('Invalid INI configuration.');
        }

        return $data;
    }
}
```

Return `null` only when the reader does not support the file format. Once a reader accepts an extension, parse/read failures should be reported instead of silently ignored.

```php
$provider = new FileProvider(
    'config/*.ini',
    readers: [new IniReader()],
);
```

## Combining providers

`ConfigLoader::load()` merges providers in the order they are passed:

```php
use Componenta\Config\ConfigLoader;

$config = ConfigLoader::load(
    $environment,
    static fn (): array => require 'config/app.php',
    static fn (): array => require 'config/local.php',
);
```

Later providers may replace scalar/map values. Generic numeric arrays are appended.

You can use `config_merge()` directly:

```php
use function Componenta\Config\config_merge;

$merged = config_merge(
    ['middleware' => ['auth']],
    ['middleware' => ['csrf']],
);

// ['middleware' => ['auth', 'csrf']]
```

## Configuration providers for packages

Extend `ConfigProvider` when a library needs to publish both application settings and dependency-container metadata:

```php
use Componenta\Config\ConfigProvider;

final class PackageConfigProvider extends ConfigProvider
{
    protected function getConfig(): array
    {
        return [
            'feature' => [
                'enabled' => true,
            ],
        ];
    }

    protected function getFactories(): array
    {
        return [
            ServiceInterface::class => ServiceFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            LoggerInterface::class => AppLogger::class,
        ];
    }
}
```

Available dependency hooks are:

```text
getFactories()
getInvokables()
getAliases()
getDelegators()
getServices()
getParameterResolvers()
shouldReplaceParameterResolvers()
getAttributeDefinitions()
shouldReplaceAttributeDefinitions()
getAttributeCapabilities()
getDependencyExtensions()
```

`getProviders()` composes child providers:

```php
protected function getProviders(): iterable
{
    return [
        new DatabaseConfigProvider(),
        new CacheConfigProvider(),
    ];
}
```

### Parameter resolvers

Priorities are integer map keys and are preserved across provider composition:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => RequestResolver::class,
        500 => TenantResolver::class,
    ];
}
```

When a later provider registers the same priority, it replaces that resolver atomically.

To build a resolver chain from scratch:

```php
protected function shouldReplaceParameterResolvers(): bool
{
    return true;
}
```

### Attribute definitions and capabilities

Containers that support attribute composition can receive definitions and capability policies through the provider:

```php
protected function getAttributeDefinitions(): array
{
    return [
        new AttributeDefinition(
            attribute: FromTenant::class,
            handler: new FromTenantHandler(),
        ),
    ];
}

protected function getAttributeCapabilities(): array
{
    return [
        new CapabilityPolicy(ValueProvider::class, maxPerTarget: 1),
    ];
}
```

To replace built-in definitions:

```php
protected function shouldReplaceAttributeDefinitions(): bool
{
    return true;
}
```

The concrete definition/capability objects belong to the consuming container package. `componenta/config` only transports and composes them.

### Container-specific extensions

If another container needs metadata not represented by the standard hooks, expose it through `getDependencyExtensions()`:

```php
protected function getDependencyExtensions(): array
{
    return [
        'container_specific_option' => [
            'enabled' => true,
        ],
    ];
}
```

Extension keys cannot replace standard dependency sections. The consuming package is responsible for validating its extension keys and values.

## Dependency merge semantics

The root `dependencies` section has schema-aware composition:

- `factories`, `aliases`, `services` and `parameter_resolvers` are identity maps; a later value for the same id/priority replaces the earlier value atomically.
- `invokables`, `attribute_definitions` and `attribute_capabilities` are list-like and append in provider order.
- delegators for the same service append in provider order.
- replacement flags are scalar values; the later provider wins.
- application configuration outside the root `dependencies` section uses normal recursive merge semantics.

This keeps resolver priorities and factory/service definitions stable instead of accidentally reindexing or recursively merging values that are meant to be atomic.

## Cache generation

Build a configuration snapshot once and export it as PHP:

```php
use Componenta\Config\ConfigLoader;

ConfigLoader::export(
    $config,
    'var/cache/config.php',
);
```

Load it later:

```php
$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    populateEnv: true,
);
```

Cache files are written to a temporary file, flushed, PHP-linted and only then activated with `rename()`. If the previous artifact is present in OPcache, it is invalidated before replacement so a successful export never leaves the old opcode attached to the new path.

Configuration must be exportable by `componenta/var-export`. Runtime-only objects such as closures should be resolved or excluded before generating a persistent cache.

## Container helpers

`ContainerValue` wraps any PSR-11 container and exposes optional lookup helpers:

```php
use Componenta\Config\ContainerValue;

$value = new ContainerValue($container, $config);

$service = $value->get(ServiceInterface::class);
$optional = $value->find('optional.service', default: null);
```

Typed entry fallback:

```php
use function Componenta\Config\entry;

$clock = $value->find(
    'clock',
    entry('fallback.clock', ClockInterface::class),
);
```

Configuration fallback:

```php
use function Componenta\Config\config_entry;
use function Componenta\Config\path;

$name = $value->find(
    'display_name',
    config_entry(path('app.name')),
);
```

## Errors

Configuration failures use `Componenta\Config\Exception\ConfigExceptionInterface`.

Common exceptions:

- `ConfigException` — missing keys, invalid providers, cache/file loading failures.
- `InvalidConfigValueException` — typed conversion failed.
- `InvalidContainerValueException` — a container entry does not satisfy the requested type.
- `EnvLoaderException` — dotenv read, parse or required-variable failure.

Do not catch generic `Throwable` for ordinary configuration control flow; catch the package exception interface when you want one boundary for configuration failures.

## License

MIT.
