# Componenta Config

`componenta/config` is the configuration runtime used by Componenta DI v5. It targets PHP 8.4+ and provides immutable configuration/environment snapshots, deterministic provider composition, typed reads, explicit lazy values, file providers and versioned persistent application-config cache.

There is no compatibility layer for older DI schemas and no development/production mode inside this package. The bootstrap layer decides whether configuration data comes from providers or from a persistent cache.

## Installation

```bash
composer require componenta/config
```

## Runtime model

A `Config` always has exactly one `Environment` snapshot:

```php
use Componenta\Config\Config;
use Componenta\Config\Environment;

$environment = Environment::fromGlobals();
$config = new Config([
    'app' => ['name' => 'Example'],
], $environment);
```

`Environment` remains part of the runtime `Config`, but it is never serialized into persistent configuration cache.

A plain string key is literal. Use `ConfigPath` for nested lookup:

```php
use function Componenta\Config\path;

$config->get('database.host');       // literal key
$config->get(path('database.host')); // nested path
```

`ConfigPath` must contain one or more non-empty dot-separated segments.

Integer top-level keys are also supported consistently by `get()`, `has()`, `only()`, `except()`, `ConfigEntry` and read-only `ArrayAccess`.

## Typed reads

```php
$config->string('name');
$config->int('port');
$config->float('ratio');
$config->bool('enabled');
$config->array('hosts');
```

Integer conversion is lossless: fractional, overflow, scientific integer strings and non-finite values are rejected rather than truncated.

Missing keys throw unless a default is supplied. A stored `null` is an existing value.

## Defaults and lazy values

Use `ConfigEntry` when a missing value should resolve another config key:

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

Lazy results are cached per `Config` or `ContainerValue` context unless `cache: false` is requested. Persistent config cache preserves `LazyValue` semantics; captured exportable values are inlined so the cached callback is self-contained.

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

Dotenv data is written only to `$_ENV`; `$_SERVER` is not mutated. `.env.example`, backup files and other `.env*` files are not loaded implicitly. Alternative basenames must be listed explicitly.

`read()` is pure and does not mutate runtime globals. Required variables may come from dotenv or deployment environment. Parse errors never include dotenv values in diagnostic messages.

## Provider composition for DI v5

`ConfigProvider` transports the dependency schema consumed by DI v5. DI owns semantic validation/canonicalization of factories, aliases, invokables, delegators and extension specifications.

Available hooks:

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

`getConfig()` cannot define the reserved root key `dependencies`.

Replacement hooks are tri-state:

```php
protected function shouldReplaceParameterResolvers(): ?bool
{
    return true; // true/false = explicit value, null = no opinion
}
```

This prevents an inherited default from accidentally cancelling an earlier provider.

### Merge semantics

At the root `dependencies` section:

- `factories`, `aliases`, `services`, `parameter_resolvers` are identity maps; later entries replace the same id/priority atomically;
- numeric `invokables` append, keyed invokables retain their key for DI v5 canonicalization;
- `attribute_definitions` and `attribute_capabilities` append;
- delegator pipelines append;
- replacement flags are scalar and the later explicit value wins.

Outside `dependencies`, normal recursive configuration merge applies: string keys recurse/replace and numeric keys append.

Malformed non-array dependency roots and malformed delegator pipelines fail before merge can change their shape.

### Delegator pipelines

A delegator callable pair must be an item inside the pipeline:

```php
protected function getDelegators(): array
{
    return [
        Service::class => [
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

Matched files are processed in sorted path order. JSON integers outside the platform integer range are preserved as exact strings rather than converted to imprecise floats. Unsupported matched files, unreadable files, parse failures and invalid root values fail fast.

Custom formats implement `Componenta\Config\Reader\FileReaderInterface`.

## Persistent application-config cache

The config cache owns only application/package configuration. It deliberately excludes two runtime/bootstrap concerns:

- `Environment` is read at runtime and is never serialized;
- the reserved `dependencies` root belongs to DI v5 and is stored by `DiCacheGenerator`, not by `ConfigLoader`.

Build the application-config cache:

```php
ConfigLoader::export($config, 'var/cache/config.php');
```

The envelope is versioned:

```php
return [
    'version' => ConfigLoader::CACHE_VERSION,
    'config' => [
        // application/package configuration only
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

DI v5 loads its own dependency cache and reattaches normalized dependencies when it builds the final runtime `Config`. Provider mode and cache mode therefore converge on the same runtime shape without serializing build-time environment or duplicating the DI graph.

Unknown envelope keys, embedded dependency roots and stale cache versions fail fast. Cache files are written via a temporary file, flushed, syntax-checked and atomically activated.

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

When `Config` is not passed explicitly, the wrapped DI v5 container must expose `Config::class`; a missing bootstrap config fails fast.

## Requirements

- PHP 8.4+
- PSR-11 interfaces for container helpers
