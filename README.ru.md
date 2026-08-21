# Componenta Config

`componenta/config` — configuration runtime для Componenta DI v5 на PHP 8.4+. Пакет предоставляет immutable snapshots конфигурации и environment, детерминированную композицию providers, typed reads, runtime-bound environment references, явные lazy values, file providers и версионированный persistent cache конфигурации приложения.

В пакете нет compatibility layer для старых DI schemas и нет собственного dev/prod режима. Bootstrap-слой решает, брать данные из providers или из persistent cache.

## Runtime model

`Config` всегда содержит ровно один `Environment` snapshot. `Environment` остаётся частью runtime `Config`, но никогда не сериализуется в persistent cache.

Обычный string key является literal key; для nested lookup используется `ConfigPath`. `ConfigPath` должен содержать непустые dot-separated segments. Integer top-level keys одинаково поддерживаются `get()`, `has()`, `only()`, `except()`, `ConfigEntry` и read-only `ArrayAccess`.

## Typed reads

Доступны `string()`, `int()`, `float()`, `bool()`, `array()`. Integer conversion выполняется без потери данных: fractional, overflow, scientific integer strings и non-finite values отклоняются. Missing key без default приводит к исключению, а сохранённый `null` считается существующим значением.

## Runtime-bound environment values

Provider не должен вычислять deployment environment при построении persistent config. `env()` создаёт `EnvironmentEntry` descriptor:

```php
return [
    'database' => [
        'host' => env('DATABASE_HOST'),
        'port' => env('DATABASE_PORT', '3306'),
        'debug' => env('APP_DEBUG', 'false'),
    ],
];
```

`env()` не читает globals. Descriptor разрешается через `Config::$environment` только при чтении значения:

```php
$config->string(path('database.host'));
$config->int(path('database.port'));
$config->bool(path('database.debug'));
```

В provider и cache modes используется один и тот же runtime `Environment`; persistent cache содержит descriptor, а не build-time value. Поэтому `env()` не может заморозить build secret в cache.

Для прямого runtime-доступа используется сам `Environment`:

```php
$environment->string('APP_ENV');
$environment->bool('APP_DEBUG', false);
$environment->int('PORT', 8080);
```

`Environment::fromGlobals()` использует приоритет:

```text
process environment < $_SERVER < $_ENV
```

## ConfigEntry и LazyValue

`ConfigEntry` используется для fallback на другой config key. `LazyValue` — для явного lazy evaluation. Lazy result кэшируется отдельно для каждого `Config`/`ContainerValue`, если не указан `cache: false`.

Persistent cache поддерживает anonymous source-backed closures с exportable captured values, public static methods и методы exportable readonly objects. User-defined named function отклоняется во время cache export: его definition может находиться в provider-файле, который production bootstrap уже не загружает.

## Dotenv

`EnvLoader` по умолчанию читает только `.env`, затем `.env.local`, и всегда возвращает effective runtime `Environment`. Deployment values имеют приоритет, пока явно не передан `override: true`. Dotenv записывается только в `$_ENV`; `$_SERVER` не изменяется. Sample/backup и остальные `.env*` файлы не подхватываются автоматически.

`read()` не изменяет globals. Required variables могут приходить как из dotenv, так и из deployment environment. Ошибки парсинга никогда не включают значения из dotenv в diagnostic message.

## Provider composition для DI v5

`ConfigProvider` транспортирует schema, которую потребляет DI v5. Семантическая validation/canonicalization остаётся ответственностью DI.

Hooks:

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

`getConfig()` не может определять reserved root `dependencies`. Replacement hooks имеют tri-state семантику: `true`/`false` — explicit value, `null` — provider не меняет ранее составленное значение.

### Merge semantics

В `dependencies`:

- `factories`, `aliases`, `services`, `parameter_resolvers` — identity maps;
- numeric `invokables` append-ятся, keyed invokables сохраняют key для canonicalization в DI v5;
- `attribute_definitions` и `attribute_capabilities` append-ятся;
- delegator pipelines append-ятся;
- поздний explicit replacement flag побеждает.

За пределами dependency root применяется normal recursive config merge. Malformed dependency root/delegator pipeline отклоняются до merge. Class-method callable pair должна быть вложенным pipeline item.

## File providers

`FileProvider` поддерживает PHP и JSON. Файлы обрабатываются в отсортированном порядке. JSON integers за пределами platform int range сохраняются как точные строки. Ошибки чтения, парсинга и unsupported matched files приводят к fail-fast.

## Persistent application-config cache

Config cache хранит только application/package configuration. Из него намеренно исключаются runtime `Environment` и reserved root `dependencies`, которым владеет DI v5 `DiCacheGenerator`.

`EnvironmentEntry`, созданный `env()`, сохраняется как descriptor: runtime value и secret в cache не записываются.

```php
ConfigLoader::export($config, 'var/cache/config.php');

$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    Environment::fromGlobals(),
);
```

DI v5 загружает собственный dependency cache и при build снова добавляет normalized dependencies в итоговый runtime `Config`. Provider/cache modes сходятся к одной runtime shape без build-time environment и без дублирования DI graph.

Unknown envelope keys, embedded dependency root и stale cache version отклоняются. На POSIX активированный cache-файл имеет права `0600`.

## Container helpers

`ContainerValue` оборачивает PSR-11 container и использует тот же `Config` snapshot. Если `Config` не передан явно, wrapped DI v5 container обязан предоставлять `Config::class`; нарушение bootstrap contract приводит к fail-fast.

## Требования

- PHP 8.4+
- PSR-11 interfaces для container helpers
