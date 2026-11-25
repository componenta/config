# Componenta Config

`componenta/config` composes application configuration for PHP 8.4+, keeps
environment access explicit, and separates runtime settings from the raw
dependency definitions consumed by Componenta DI.

The package has one runtime path. Providers are executed for every application
start in every environment; Config does not generate or load a persistent cache.
This keeps development and production behavior equivalent.

## Boundary

Config owns:

- ordered provider execution and deterministic structural merging;
- an immutable application `Config` with one `Environment`;
- raw `DependencyDefinitions` as a separate composition result;
- runtime descriptors, dotenv loading, and PHP/JSON file readers.

Config does not validate whether DI can build a service, normalize definitions,
construct a container, write cache files, or choose application entry points.
Those responsibilities belong to the DI and application packages.

## Installation

```bash
composer require componenta/config
```

Requirements:

- PHP 8.4 or newer;
- `componenta/arrayable` for the shared array conversion contract;
- `psr/container` for `ContainerValue` and the container helper.

## Core concepts

A configuration provider is a callable. It returns either one contribution as
an array or an iterable of array contributions. `ConfigFactory` calls every
provider occurrence exactly once, folds all contributions in order, and returns
a `ConfigComposition`:

- `config` contains only application sections;
- `dependencies` contains raw container definition sections.

`DependencyDefinitions` is a transport object, not a built dependency graph.
It does not normalize class names, trigger autoload, or decide whether a service
is constructible. Pass it to the DI composition root; do not expose it as an
application configuration section.

## Quick start

```php
<?php

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigProvider;
use Componenta\Config\FileProvider;
use Componenta\Config\Loader\EnvLoader;
use function Componenta\Config\path;

final class AppConfigProvider extends ConfigProvider
{
    protected function getConfig(): array
    {
        return [
            'app' => ['name' => 'Example'],
        ];
    }

    protected function getFactories(): array
    {
        return [
            App::class => AppFactory::class,
        ];
    }
}

$environment = (new EnvLoader(__DIR__))->load();

$composition = (new ConfigFactory())->create(
    $environment,
    new AppConfigProvider(),
    new FileProvider(__DIR__ . '/config/*.{php,json}'),
);

$config = $composition->config;
$dependencies = $composition->dependencies;

echo $config->string(path('app.name'));
```

The same `Environment` object passed to `ConfigFactory::create()` is retained
by identity in the resulting `Config`.

## Provider composition

`ConfigProvider` produces one contribution. Application code prepares the
complete ordered provider list; providers do not recursively discover or invoke
other providers.

The available dependency hooks are:

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

`getConfig()` returns application sections and must not define the reserved
`dependencies` root. Replacement hooks are tri-state: `true` and `false`
are explicit values; `null` leaves the previously composed value unchanged.

### Application merge rules

- list plus list appends in provider order;
- map plus map merges recursively by exact integer or string key;
- a list/map shape change replaces the earlier value;
- scalars, objects, and different value shapes are replaced by the later value;
- `ConfigOverride::replace($value)` replaces a subtree explicitly;
- `ConfigOverride::remove()` removes a key.

Override markers are map values. Lists are append-only and reject markers.

PHP array references and nesting deeper than 64 levels are rejected with
`ConfigMergeException`.

### Dependency section merge rules

All dependency sections are optional. DI registers its built-in parameter
resolvers and attribute definitions by default. Use `parameter_resolvers` for
custom resolvers, `attribute_definitions` to register attributes, and
`attribute_capabilities` to configure capability limits. A `true` replacement
flag disables the corresponding default registrations.

- `factories`, `aliases`, `services`, and `parameter_resolvers` merge by
  key; the later entry replaces the earlier entry atomically;
- numeric `invokables` append, while keyed invokables replace the same key;
- delegator pipelines, attribute definitions, and attribute capabilities append
  in provider order;
- the last explicitly supplied replacement flag wins.

Application values and dependency sections are folded independently. Adding or
omitting the reserved dependency root does not change the application list/map
shape, and replacing the application root does not discard previously composed
dependency definitions.

Config validates only the shapes needed to merge these sections safely. DI owns
semantic validation of factories, callables, aliases, types, and service
constructibility.

## Reading configuration

A string or integer passed to `Config::get()` is a literal top-level key.
`ConfigPath` performs nested lookup:

```php
use function Componenta\Config\path;

$config->get('database.host');       // literal key
$config->get(path('database.host')); // nested path
```

Typed accessors are available for common values:

```php
$config->string('name');
$config->int('port');
$config->float('ratio');
$config->bool('enabled');
$config->array('hosts');
```

Missing values throw unless a default is supplied. Stored `null`, `false`,
an empty string, and an empty array are existing values. Integer conversion is
lossless; fractional, overflowing, non-finite, or ambiguous input is rejected.

`toArray()`, iteration, `only()`, and `except()` expose the stored
application sections. They do not add dependency definitions or eagerly resolve
the whole configuration graph.

`Config` and `Environment` capture array values when constructed. Editing an
input array or an exported array leaves the snapshot stable, including when
the array contains explicit PHP references. Object and resource values are
shared with the caller. `Config::$data` exposes the raw application sections
as a read-only property.

## Runtime descriptors

The helper functions create explicit runtime values:

```php
use Componenta\Config\Config;
use function Componenta\Config\config_entry;
use function Componenta\Config\env;
use function Componenta\Config\lazy;
use function Componenta\Config\path;

return [
    'host' => env('DATABASE_HOST'),
    'port' => env('DATABASE_PORT', '3306'),
    'display_name' => config_entry(path('app.name')),
    'dsn' => lazy(
        static fn(Config $config): string =>
            'mysql:host=' . $config->string('host'),
    ),
];
```

- `EnvironmentEntry` reads the `Environment` attached to the same `Config`;
- `ConfigEntry` reads another key from that `Config`;
- `LazyValue` executes only when read;
- ordinary callables remain ordinary values and are not executed automatically.

When the selected value is an array, descriptors are resolved throughout that
subtree while keys are preserved. Descriptor results are also resolved
recursively. Cycles fail with
`ConfigResolutionException` and include the resolution path. A cached
`LazyValue` stores one successful result per `Config` or `ContainerValue`
context. A failed resolution allows a retry. Re-entering the same descriptor in
the same context and Fiber is a cycle. Another Fiber may resolve concurrently.
The first successful completion becomes the cached value.

A `Config` clone tracks active lookups independently. A `LazyValue` clone
inherits completed results and caches later resolutions independently.

## Environment loading

`EnvLoader` reads `.env` and then `.env.local` by default:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader(
    paths: [__DIR__, '/etc/example'],
    required: ['APP_ENV'],
))->load();
```

The loader is pure: it never writes to `$_ENV`, `$_SERVER`, or the process
environment. Existing process values win by default. Pass `override: true` to
`load()` when dotenv file values should win in the returned snapshot; globals
still remain unchanged.

Only explicitly listed basenames are loaded. Sample and backup files are not
picked up automatically. `read()` returns only parsed dotenv values (or
`null` when no file exists), and parse diagnostics never include secret values.

`Environment::fromGlobals()` creates an independent snapshot with this
precedence:

```text
process environment < $_SERVER < $_ENV
```

## File providers and readers

`FileProvider` supports PHP and JSON and returns one contribution per matched
file in lexical path order. It does not merge files itself:

```php
$provider = new FileProvider(__DIR__ . '/config/*.{php,json}');

foreach ($provider() as $contribution) {
    // ConfigFactory performs the merge.
}
```

PHP files are included in an isolated static scope and must return an array.
JSON must contain an object or array at the root; integers outside the platform
range are preserved as strings. Implement `FileReaderInterface` to add another
format.

## Container helpers

`ContainerValue` wraps a PSR-11 container and carries the same runtime
`Config`. Both values are required constructor arguments and are retained by
identity; `ContainerValue` never discovers, rebuilds, or clones Config. Its
optional fallbacks support `ContainerEntry`, `ConfigEntry`, `EnvironmentEntry`,
and `LazyValue`.

For an existing entry, `find()` checks the type passed via `type:` or takes
the type from a `ContainerEntry` fallback. For a missing entry, `find()`
returns or resolves the fallback according to its own contract.

## Errors

All expected package failures implement `ConfigExceptionInterface`. Important
types include:

- `InvalidConfigArgumentException` for invalid public arguments;
- `ConfigMergeException` for unsafe or malformed composition;
- `ConfigResolutionException` for descriptor cycles;
- `InvalidConfigValueException` and `InvalidContainerValueException` for
  failed type contracts;
- `EnvLoaderException` for dotenv failures;
- `ConfigException` for missing configuration and reader failures.

Provider exceptions are propagated unchanged. Wrapped reader failures preserve
the source exception in `getPrevious()`.

## Related package

[`componenta/di`](../di/README.md) consumes `DependencyDefinitions`, validates
their meaning, and constructs the application container.
