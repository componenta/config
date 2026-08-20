# Componenta Config

`componenta/config` provides immutable runtime configuration for PHP 8.4+: application config, runtime environment snapshots, deterministic provider composition, typed access, dotenv loading, file providers and versioned PHP cache generation.

The dependency provider schema is designed for Componenta DI v5. Config composes and transports that schema; DI v5 owns validation and canonicalization of container definitions.

## Installation

```bash
composer require componenta/config
```

## Runtime configuration

```php
use Componenta\Config\Config;
use Componenta\Config\Environment;
use function Componenta\Config\path;

$environment = Environment::fromGlobals();

$config = new Config([
    'app' => [
        'name' => 'Example',
        'debug' => false,
    ],
], $environment);

$name = $config->string(path('app.name'));
$debug = $config->bool(path('app.debug'));
```

`Config` always contains one `Environment` snapshot. Creating `Config` directly without an environment uses an empty snapshot.

A plain integer or string is a literal top-level key. `ConfigPath` performs nested lookup:

```php
$config = new Config([
    0 => 'first',
    'database.host' => 'literal',
    'database' => ['host' => 'localhost'],
]);

$config->get(0);                     // first
$config->get('database.host');       // literal
$config->get(path('database.host')); // localhost
```

`Config` implements `Countable`, `IteratorAggregate`, read-only `ArrayAccess` and `Componenta\Arrayable\Arrayable`.

## Typed access

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$ratio = $config->float('ratio', 1.0);
$enabled = $config->bool('enabled', false);
$drivers = $config->array('drivers', []);
```

Conversions fail explicitly when they would be ambiguous or lossy. In particular, integer conversion does not truncate fractional values and does not accept scientific-notation strings as integers.

## Defaults, references and lazy values

A missing key without a default throws `ConfigException`:

```php
$config->get('required');
```

Use `ConfigEntry` to resolve a fallback from another config key:

```php
use function Componenta\Config\config_entry;

$name = $config->string(
    'display_name',
    config_entry(path('app.name')),
);
```

Plain callables are ordinary values. Explicit lazy evaluation uses `LazyValue`:

```php
use function Componenta\Config\lazy;

$config = new Config([
    'host' => 'localhost',
    'dsn' => lazy(
        static fn (Config $config): string =>
            'mysql:host=' . $config->string('host'),
    ),
]);
```

Lazy results are cached per `Config` or `ContainerValue` context unless `cache: false` is requested.

## Environment

`Environment` is an immutable runtime snapshot:

```php
$environment = Environment::fromGlobals();

$environment->string('APP_ENV');
$environment->bool('APP_DEBUG', false);
$environment->int('PORT', 8080);
```

Global precedence is:

```text
process environment < $_SERVER < $_ENV
```

A `ConfigPath` maps to upper snake case:

```php
$environment->string(path('database.host')); // DATABASE_HOST
```

### Environment helpers

`env()` returns the raw value. Conversion is always explicit through a typed helper:

```php
use function Componenta\Config\env;
use function Componenta\Config\env_array;
use function Componenta\Config\env_bool;
use function Componenta\Config\env_float;
use function Componenta\Config\env_int;
use function Componenta\Config\env_string;

$raw = env('PORT');              // "8080"
$port = env_int('PORT', 8080);   // 8080
$name = env_string('APP_NAME');
$debug = env_bool('APP_DEBUG', false);
$ratio = env_float('RATIO', 1.0);
$hosts = env_array('HOSTS', []);
```

Lexical string values are preserved by `env_string()`: for example `001`, `true` and `false` remain those exact strings.

## Dotenv loading

`EnvLoader` loads `.env` and `.env.local` in that order:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load()
    ?? Environment::fromGlobals();
```

Existing deployment values remain authoritative unless `override: true` is requested. Sample and backup files are never discovered implicitly; alternative filenames must be listed explicitly.

`read()` parses dotenv files without mutating global state. `load()` populates the selected globals and returns the effective runtime `Environment` snapshot.

## Loading application config

Compose providers in declaration order:

```php
use Componenta\Config\ConfigLoader;

$config = ConfigLoader::load(
    $environment,
    new AppConfigProvider(),
    new PackageConfigProvider(),
);
```

Providers may return arrays or iterables.

`config_merge()` uses deterministic semantics:

- generic string keys recursively merge;
- generic numeric entries append;
- DI identity maps (`factories`, `aliases`, `services`, `parameter_resolvers`) replace the value for the same semantic key atomically;
- list-like DI sections append in provider order;
- parameter-resolver integer priorities are preserved and never reindexed;
- replacement flags are scalar values, so the later explicit value wins.

## File providers

`FileProvider` supports PHP and JSON by default:

```php
use Componenta\Config\FileProvider;

$provider = new FileProvider('config/*.{php,json}');
$data = $provider();
```

Matched files are sorted before merging. Unsupported matched files, unreadable files, parse errors and invalid root values fail fast.

Custom formats implement `Componenta\Config\Reader\FileReaderInterface`.

## ConfigProvider and DI v5

A package provider extends `ConfigProvider`:

```php
use Componenta\Config\ConfigProvider;

final class PackageConfigProvider extends ConfigProvider
{
    protected function getConfig(): array
    {
        return [
            'feature' => ['enabled' => true],
        ];
    }

    protected function getFactories(): array
    {
        return [
            ServiceInterface::class => ServiceFactory::class,
        ];
    }

    protected function getInvokables(): array
    {
        return [
            SimpleService::class,
            ServiceInterface::class => ConcreteService::class,
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
```

`getConfig()` may not define the reserved root key `dependencies`.

ConfigProvider intentionally does not validate or canonicalize DI definitions. For example, keyed invokables are preserved until DI v5 resolves their aliases. This keeps one authoritative implementation of DI semantics.

### Replacement flags

Replacement hooks are tri-state:

```php
protected function shouldReplaceParameterResolvers(): ?bool
{
    return true;
}
```

- `null` — this provider has no opinion and does not change an earlier value;
- `true` — replace the built-in chain;
- `false` — explicitly cancel an earlier replacement request.

The same rule applies to `shouldReplaceAttributeDefinitions()`.

### Delegators

A delegator value is always a pipeline. Callable pairs are entries inside that pipeline:

```php
protected function getDelegators(): array
{
    return [
        ServiceInterface::class => [
            LoggingDelegator::class,
            [MetricsDelegator::class, 'decorate'],
        ],
    ];
}
```

Keeping callable pairs nested makes provider composition unambiguous: multiple providers can append delegators without flattening a callable pair into unrelated entries.

## Persistent cache

Persistent config contains application/package config only. Environment is runtime state and is never serialized into the cache.

Build the cache:

```php
ConfigLoader::export($config, 'var/cache/config.php');
```

The generated envelope is versioned:

```php
return [
    'version' => ConfigLoader::CACHE_VERSION,
    'config' => [
        // persistent configuration
    ],
];
```

At runtime, bind the current environment explicitly:

```php
$environment = Environment::fromGlobals();

$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    $environment,
);
```

This is a core parity invariant: cached and provider-loaded configuration use the same runtime `Environment`; build-time environment values cannot leak into production through the config cache.

Unknown cache-envelope keys and stale cache versions fail fast. Cache files are written through a temporary file, flushed, syntax-checked and atomically activated.

## Container helpers

`ContainerValue` wraps a PSR-11 container and exposes the same `Config` snapshot used by the runtime:

```php
use Componenta\Config\ContainerValue;
use function Componenta\Config\entry;

$value = new ContainerValue($container, $config);

$service = $value->get(ServiceInterface::class);
$optional = $value->find('optional.service', default: null);
$clock = $value->find('clock', entry('fallback.clock', ClockInterface::class));
```

## Errors

Configuration failures use `Componenta\Config\Exception\ConfigExceptionInterface`.

- `ConfigException` — missing keys, provider, cache or file failures;
- `InvalidConfigValueException` — typed conversion failure;
- `InvalidContainerValueException` — container value type mismatch;
- `EnvLoaderException` — dotenv read, parse or required-variable failure.

## Requirements

- PHP 8.4+
- PSR-11 interfaces for container helpers
