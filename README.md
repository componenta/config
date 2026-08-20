# Componenta Config

`componenta/config` is the configuration layer used by Componenta DI v5. It provides immutable runtime configuration, environment snapshots, deterministic provider composition, typed reads, dotenv loading, file providers and versioned PHP cache generation for PHP 8.4+.

The package has no development/production mode. The application decides whether configuration data comes from providers or a persistent cache; the resulting `Config` and `Environment` semantics are identical.

## Installation

```bash
composer require componenta/config
```

## Runtime model

`Config` combines persistent application/package data with exactly one runtime `Environment` snapshot:

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

When `Config` is created without an explicit environment it receives an empty `Environment`; the property is never nullable.

Integer and string keys are literal top-level keys. `ConfigPath` performs nested lookup:

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

`Config` is immutable and implements `Countable`, `IteratorAggregate`, read-only `ArrayAccess` and `Componenta\Arrayable\Arrayable`.

## Typed values

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$ratio = $config->float('ratio', 1.0);
$enabled = $config->bool('enabled', false);
$drivers = $config->array('drivers', []);
```

Integer conversion is strict: fractional values, exponent-form strings and values outside the platform integer range are rejected instead of being truncated or saturated.

## Defaults and lazy values

A missing key without a default raises `ConfigException`.

Use `ConfigEntry` to resolve a fallback from another configuration key:

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

## Runtime environment

`Environment` is an immutable snapshot. `Environment::fromGlobals()` composes values with this precedence:

```text
process environment < $_SERVER < $_ENV
```

Typed reads are available directly on the snapshot:

```php
$environment->string('APP_ENV');
$environment->bool('APP_DEBUG', false);
$environment->int('PORT', 8080);
```

A `ConfigPath` maps to upper snake case:

```php
$environment->string(path('database.host')); // DATABASE_HOST
```

### Environment helpers

`env()` returns the raw value. Type conversion is always explicit:

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

`env_string()` preserves lexical strings such as `001`, `true` and `false`.

## Dotenv loading

`EnvLoader` loads `.env` and `.env.local` in that order. It always returns the effective runtime `Environment` snapshot:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load();
```

Existing deployment values remain authoritative unless `override: true` is requested:

```php
$environment = (new EnvLoader('.'))->load(override: true);
```

`read()` is pure and only parses configured files. `load()` writes dotenv values to `$_ENV`; it does not mirror them into `$_SERVER`. Existing `$_SERVER` and process values are still valid runtime environment sources.

Sample, backup and other `.env*` files are never discovered implicitly. Alternative files must be named explicitly:

```php
$environment = (new EnvLoader(
    '.',
    filenames: ['.env', '.env.production'],
))->load();
```

Required variables may come from dotenv files or the deployment environment:

```php
$environment = (new EnvLoader(
    '.',
    required: ['APP_KEY', 'DATABASE_URL'],
))->load();
```

## Loading configuration

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

`config_merge()` has deterministic DI-v5-aware composition rules:

- generic string keys merge recursively;
- generic numeric entries append;
- `factories`, `aliases`, `services` and `parameter_resolvers` are identity maps and replace the same semantic key atomically;
- parameter-resolver integer priorities are preserved and never reindexed;
- `invokables`, `attribute_definitions` and `attribute_capabilities` append in provider order;
- delegators compose as pipelines;
- replacement flags are scalar values and the later explicit value wins.

## ConfigProvider and DI v5

`ConfigProvider` exposes exactly the dependency sections consumed by DI v5:

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

Application configuration returned by `getConfig()` cannot define the reserved root key `dependencies`.

Config performs only composition-safe shape checks. DI v5 remains the sole owner of semantic validation and canonicalization of factories, aliases, invokables, resolvers and attribute definitions.

### Replacement flags

Replacement hooks are tri-state:

```php
protected function shouldReplaceParameterResolvers(): ?bool
{
    return true;
}
```

- `null` — this provider does not change an earlier value;
- `true` — replace the built-in chain;
- `false` — explicitly cancel an earlier replacement request.

The same contract applies to `shouldReplaceAttributeDefinitions()`.

### Delegators

A delegator value is always a pipeline list. Callable pairs must be nested as one pipeline entry:

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

A direct callable pair such as `[MetricsDelegator::class, 'decorate']` is rejected because its meaning would otherwise change when multiple providers are merged.

## File providers

`FileProvider` supports PHP and JSON by default:

```php
use Componenta\Config\FileProvider;

$data = (new FileProvider('config/*.{php,json}'))();
```

Matched files are processed in sorted path order. Unsupported matched files, unreadable files, parse failures and invalid root values fail fast.

Custom formats implement `Componenta\Config\Reader\FileReaderInterface`.

## Persistent cache

Persistent cache contains only application/package config. Runtime environment is never serialized.

Build the cache:

```php
ConfigLoader::export($config, 'var/cache/config.php');
```

The cache envelope is versioned:

```php
return [
    'version' => ConfigLoader::CACHE_VERSION,
    'config' => [
        // persistent configuration
    ],
];
```

Load it with the current runtime environment:

```php
$environment = Environment::fromGlobals();

$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    $environment,
);
```

Provider mode and cache mode therefore share the same runtime-environment semantics, and build-time secrets cannot leak through the config cache.

Unknown cache-envelope keys and stale cache versions fail fast. Cache files are written via a temporary file, flushed, syntax-checked and atomically activated.

## Container helpers

`ContainerValue` wraps a PSR-11 container and carries the same `Config` snapshot:

```php
use Componenta\Config\ContainerValue;
use function Componenta\Config\entry;

$value = new ContainerValue($container, $config);

$service = $value->get(ServiceInterface::class);
$optional = $value->find('optional.service', default: null);
$clock = $value->find('clock', entry('fallback.clock', ClockInterface::class));
```

## Requirements

- PHP 8.4+
- PSR-11 interfaces for container helpers
