# Componenta Config

Immutable configuration and environment access for Componenta libraries.

## Installation

```bash
composer require componenta/config
```

This package intentionally does not declare an automatic provider in `extra.componenta.config-providers`.
`Componenta\Config\ConfigProvider` is the base class used by package and application providers.

## Requirements

- PHP 8.4+
- `componenta/var-export` for cache export

## Related Packages

| Package | Why it matters here |
|---|---|
| `componenta/var-export` | Exports configuration into executable PHP cache files. |
| `componenta/di` | Consumes the `dependencies` section returned by `ConfigProvider` classes. |
| `componenta/app` | Chooses provider loading in development or cache loading in production. |

## What It Provides

- `Config`: immutable configuration container with literal keys and `ConfigPath` keys.
- `Environment`: immutable environment container with typed accessors.
- `ConfigLoader`: static loader/exporter for provider arrays and cache files.
- `ConfigProvider`: base class for modular DI configuration.
- `FileProvider`: PHP/JSON file provider with Componenta merge semantics.
- `ContainerValue`: PSR-11-compatible wrapper around a container for configuration factories, with typed lookup helpers and direct access to `Config`.
- `ContainerEntry`, `ConfigEntry`, `LazyValue`: explicit value objects for typed container lookup, references to other config keys, and lazy value evaluation.

## Loading Configuration

`ConfigLoader` does not decide dev/prod mode. Runtime bootstrap chooses whether to load providers or a prebuilt cache file.

```php
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\Config\FileProvider;

$environment = new Environment($_ENV);

$config = ConfigLoader::load(
    $environment,
    new FileProvider(__DIR__ . '/config/*.php'),
    static fn(): array => ['app' => ['debug' => false]],
);
```

For production cache:

```php
use Componenta\Config\ConfigLoader;

$config = ConfigLoader::loadFromFile(__DIR__ . '/var/cache/config.php', populateEnv: true);
```

To build cache:

```php
use Componenta\Config\ConfigLoader;

ConfigLoader::export($config, __DIR__ . '/var/cache/config.php');
```

The exported file returns:

```php
[
    'config' => [...],
    'environment' => [...],
]
```

## Accessing Values

String keys are literal. `ConfigPath` keys resolve nested arrays.

```php
use function Componenta\Config\path;

$config->get('database.host');        // literal key: $data['database.host']
$config->get(path('database.host'));  // nested key: $data['database']['host']
```

Typed accessors convert values or throw:

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$debug = $config->bool(path('app.debug'), false);
$tags = $config->array(path('app.tags'), []);
```

If no default is provided, a missing key throws `ConfigException`.
If a value cannot be converted to the requested type, `InvalidConfigValueException` is thrown.
Defaults may be plain values, `config_entry(...)`, or `lazy(...)`; typed accessors resolve the default first and then validate the resulting type.

## Typed Container Access

`ContainerValue` wraps `Psr\Container\ContainerInterface` for configuration factories. It still implements PSR-11, so legacy factories typed as `ContainerInterface` continue to work, but new framework factories can type `ContainerValue` when they need fallback helpers or access to the application config.

```php
use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerValue;
use function Componenta\Config\config_entry;
use function Componenta\Config\entry;
use function Componenta\Config\lazy;
use Psr\Log\LoggerInterface;

$config = new Config(['app' => ['name' => 'Componenta']]);
$services = new ContainerValue($container, $config);

$logger = $services->get(LoggerInterface::class);
$logger = $services->get(LoggerInterface::class, LoggerInterface::class);
$auditLogger = $services->find('audit.logger', entry('logger.null', LoggerInterface::class));
$appName = $services->find('app.name', config_entry(new ConfigPath('app.name'), 'Componenta'));
$fallbackName = $services->find('fallback.name', lazy(
    static fn (ContainerValue $container): string => $container->config->string(new ConfigPath('app.name'), 'Componenta'),
));
$appName = $services->config->string(new ConfigPath('app.name'), 'Componenta');
```

`get($id)` is normal PSR-11 lookup and returns the raw entry. `get($id, $type)` additionally asserts the resolved entry type and throws `InvalidContainerValueException` when it does not match.
`find($id, $default)` returns the existing entry when present. When the entry is absent, it returns the default value, resolves `entry(...)` from the container, resolves `config_entry(...)` from `$container->config`, or executes `lazy(...)` with the current `ContainerValue`. A plain callable default is returned as a callable value and is not executed.

When the entry `$id` exists and the default is `entry(..., Type::class)`, the type from `entry()` is applied to the existing entry. This lets optional overrides keep the same type assertion as their fallback.

## Boolean Conversion

Accepted truthy values:

- `true`
- `1`
- `yes`
- `on`
- `enabled`
- `y`

Accepted falsy values:

- `false`
- `0`
- `no`
- `off`
- `disabled`
- `n`
- empty string

Ambiguous values such as `42`, `-1`, arrays, `null`, or unknown strings are not silently coerced to bool.

## Lazy Values

Plain callable config values are data and are not executed by `Config`. Use `lazy(...)` for computed configuration values. Lazy config values receive the current `Config` instance and are cached after the first call by default. Use `lazy($callback, cache: false)` when the value must be recomputed on every read.

Callable defaults are also values: they are returned as callables and are not executed. Use `config_entry(...)` when a missing key should fall back to another config key.

```php
use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use function Componenta\Config\path;
use function Componenta\Config\lazy;

$config = ConfigLoader::load(null, static fn(): array => [
    'database' => [
        'host' => 'localhost',
        'dsn' => lazy(static fn(Config $config): string => sprintf(
            'mysql:host=%s',
            $config->string(path('database.host')),
        )),
    ],
]);

$dsn = $config->string(path('database.dsn'));
```

Plain callables are returned unchanged:

```php
$callable = static fn(): string => 'raw';
$config = new Config(['callback' => $callable]);

$raw = $config->get('callback'); // same callable instance
```

Disable lazy caching explicitly:

```php
$fresh = lazy(static fn(Config $config): string => uniqid('', true), cache: false);
```

## Environment

`EnvLoader` loads `.env*` files from one or more directories and returns `?Environment`.

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader(__DIR__))->load(
    override: false,
    populateServer: true,
);
```

If no `.env*` files are found and no globals are available, `load()` returns `null`.

Environment keys can be strings or `ConfigPath` objects. Paths are converted to uppercase snake case:

```php
$environment->string('APP_ENV', 'production');
$environment->string(path('database.host')); // DATABASE_HOST
```

## ConfigProvider

Modules extend `ConfigProvider` to register DI metadata and module config.
The base provider builds the final array from overridable sections:

| Method | Section |
|---|---|
| `getProviders()` | Child providers merged after the current provider. |
| `getConfig()` | Application/package config outside `dependencies`. |
| `getFactories()` | Service factories, keyed by service id. |
| `getInvokables()` | Classes instantiated directly; keyed entries also create aliases. |
| `getAutowires()` | Classes resolved through autowiring. |
| `getAliases()` | Explicit service aliases. |
| `getDelegators()` | Delegator factories, keyed by decorated service id. |
| `getServices()` | Pre-built service instances. |
| `getParameterResolvers()` | Custom constructor/method parameter resolvers keyed by priority. |
| `getPropertyResolvers()` | Custom property resolvers keyed by priority. |

```php
use Componenta\Config\ConfigProvider;

final class AppConfigProvider extends ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            LoggerInterface::class => LoggerFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            CacheInterface::class => RedisCache::class,
        ];
    }

    protected function getConfig(): array
    {
        return [
            'app' => ['name' => 'Ophire'],
        ];
    }
}
```

Calling a provider returns a config array with a `dependencies` section.
