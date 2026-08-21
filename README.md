# Componenta Config

`componenta/config` is the configuration runtime used by Componenta DI v5. It targets PHP 8.4+ and provides immutable configuration/environment snapshots, deterministic provider composition, typed reads, runtime-bound environment references, explicit lazy values, file providers and versioned persistent application-config cache.

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

`ConfigPath` must contain one or more non-empty dot-separated segments. Integer top-level keys are supported consistently by `get()`, `has()`, `only()`, `except()`, `ConfigEntry` and read-only `ArrayAccess`.

## Typed reads

```php
$config->string('name');
$config->int('port');
$config->float('ratio');
$config->bool('enabled');
$config->array('hosts');
```

Integer conversion is lossless: fractional, overflow, scientific integer strings and non-finite values are rejected rather than truncated. Missing keys throw unless a default is supplied; a stored `null` is an existing value.

## Runtime-bound environment values

Provider configuration must not read deployment environment eagerly. Use `env()` to store an `EnvironmentEntry` descriptor:

```php
use function Componenta\Config\env;

return [
    'database' => [
        'host' => env('DATABASE_HOST'),
        'port' => env('DATABASE_PORT', '3306'),
        'debug' => env('APP_DEBUG', 'false'),
    ],
];
```

`env()` does **not** read `$_ENV`, `$_SERVER` or the process environment. The descriptor is resolved against the `Environment` belonging to the current `Config` when the value is read:

```php
$config->string(path('database.host'));
$config->int(path('database.port'));
$config->bool(path('database.debug'));
```

This is the same in provider and cache modes: config cache stores the descriptor, never the resolved environment value. Build-time secrets therefore cannot be frozen into application config by `env()`.

For direct runtime environment access use the immutable snapshot itself:

```php
$environment->string('APP_ENV');
$environment->bool('APP_DEBUG', false);
$environment->int('PORT', 8080);
$environment->string(path('database.host')); // DATABASE_HOST
```

`Environment::fromGlobals()` composes values with this precedence:

```text
process environment < $_SERVER < $_ENV
```

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
    'dsn' => lazy(
        static fn (Config $config): string =>
            'mysql:host=' . $config->string('host'),
    ),
], $environment);
```

Lazy results are cached per `Config` or `ContainerValue` context unless `cache: false` is requested. Circular resolution in the same runtime context fails fast. Anonymous source-backed closures with exportable captured values are persisted as self-contained callbacks; class scope is preserved only when it can be reconstructed exactly. Public static methods and methods on exportable readonly objects are also portable. A user-defined named function is rejected during cache export because its definition may have come from a provider file that is not loaded during production cache bootstrap.

Plain `Closure` values are supported by persistent cache only when they are global-scope, unbound and otherwise exportable. Class-scoped or bound plain closures are rejected rather than cached with different runtime binding semantics.

## Dotenv loading

`EnvLoader` loads `.env` and `.env.local` in that order and always returns the effective runtime `Environment` snapshot:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load();
```

Existing deployment values remain authoritative unless `override: true` is requested. Dotenv data is written only to `$_ENV`; `$_SERVER` is not mutated. `.env.example`, backup files and other `.env*` files are not loaded implicitly. Alternative basenames must be listed explicitly.

`read()` is pure and does not mutate runtime globals. Required variables may come from dotenv or deployment environment. Parse errors never include dotenv values in diagnostic messages.

## Provider composition for DI v5

`ConfigProvider` transports the exact dependency sections consumed by DI v5. Config validates only structural invariants required for deterministic composition: known section names, array/bool section shapes and composable delegator pipelines. DI v5 remains responsible for semantic validation and canonicalization of the values inside those sections.

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

`getConfig()` cannot define the reserved root key `dependencies`. Replacement hooks are tri-state: `true`/`false` are explicit values, while `null` means that provider has no opinion and does not cancel an earlier value.

### Merge semantics

At the root `dependencies` section:

- `factories`, `aliases`, `services`, `parameter_resolvers` are identity maps; later entries replace the same id/priority atomically;
- numeric `invokables` append, keyed invokables retain their key for DI v5 canonicalization;
- `attribute_definitions` and `attribute_capabilities` append;
- delegator pipelines append;
- replacement flags are scalar and the later explicit value wins.

Outside `dependencies`, two true PHP lists append. Other arrays are maps: both string and integer key identity is preserved, same-key nested arrays merge recursively, and later scalar values replace earlier ones. Unknown DI sections and malformed dependency roots, array/bool sections or delegator pipelines fail before merge can change their meaning. Excessively deep recursive graphs fail fast instead of exhausting the stack.

A class-method callable pair must be nested as one delegator pipeline item:

```php
return [
    Service::class => [
        [MetricsDelegator::class, 'decorate'],
    ],
];
```

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

- the `Environment` snapshot is supplied at runtime and is never serialized;
- the reserved `dependencies` root belongs to DI v5 and is stored by `DiCacheGenerator`, not by `ConfigLoader`.

Runtime-bound `EnvironmentEntry` values created by `env()` are persisted as descriptors, so their resolved values and secrets are not written into the cache.

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
$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    Environment::fromGlobals(),
);
```

DI v5 loads its own dependency cache and reattaches normalized dependencies when it builds the final runtime `Config`. Provider mode and cache mode therefore converge on the same runtime shape without serializing build-time environment or duplicating the DI graph.

Unknown envelope keys, embedded dependency roots, stale cache versions and graphs beyond the supported exporter depth fail fast. Cache files are written via a temporary file, flushed, syntax-checked and atomically activated. File ownership and permissions are deployment concerns and follow the process/filesystem policy (including umask); configure them so the runtime user can read the cache while keeping sensitive literal configuration appropriately protected.

## Container helpers

`ContainerValue` wraps a PSR-11 container and carries the same `Config` snapshot. Its fallbacks understand `ContainerEntry`, `ConfigEntry`, `EnvironmentEntry` and `LazyValue`. When `Config` is not passed explicitly, the wrapped DI v5 container must expose `Config::class`; a missing bootstrap config fails fast.

## Requirements

- PHP 8.4+
- PSR-11 interfaces for container helpers
