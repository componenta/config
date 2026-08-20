# Componenta Config

`componenta/config` — configuration runtime для Componenta DI v5 на PHP 8.4+. Пакет предоставляет immutable snapshots конфигурации и environment, детерминированную композицию providers, typed reads, явные lazy values, file providers и версионированный persistent cache конфигурации приложения.

В пакете нет compatibility layer для старых DI schemas и нет собственного dev/prod режима. Bootstrap-слой решает, брать данные из providers или из persistent cache.

## Runtime model

`Config` всегда содержит ровно один `Environment` snapshot:

```php
use Componenta\Config\Config;
use Componenta\Config\Environment;

$environment = Environment::fromGlobals();
$config = new Config([
    'app' => ['name' => 'Example'],
], $environment);
```

`Environment` остаётся частью runtime `Config`, но никогда не сериализуется в persistent cache.

Обычный string key является literal key. Для nested lookup используется `ConfigPath`:

```php
use function Componenta\Config\path;

$config->get('database.host');
$config->get(path('database.host'));
```

`ConfigPath` должен содержать один или несколько непустых сегментов, разделённых точкой.

Integer top-level keys одинаково поддерживаются `get()`, `has()`, `only()`, `except()`, `ConfigEntry` и read-only `ArrayAccess`.

## Typed reads

```php
$config->string('name');
$config->int('port');
$config->float('ratio');
$config->bool('enabled');
$config->array('hosts');
```

Integer conversion выполняется без потери данных: дробные значения, overflow, scientific integer strings и non-finite values отклоняются, а не усекаются.

Missing key без default приводит к исключению. Сохранённый `null` считается существующим значением.

## Defaults и lazy values

Для fallback на другой config key используется `ConfigEntry`:

```php
$name = $config->string(
    'display_name',
    config_entry(path('app.name')),
);
```

Обычный callable является обычным значением. Для явного lazy evaluation используется `LazyValue`:

```php
$config = new Config([
    'dsn' => lazy(
        static fn (Config $config): string =>
            'mysql:host=' . $config->string('host'),
    ),
]);
```

Lazy result кэшируется отдельно для каждого `Config`/`ContainerValue`, если не указан `cache: false`. Persistent config cache сохраняет семантику `LazyValue`; захваченные exportable values инлайнятся при build, поэтому cached callback является self-contained.

## Runtime environment

`Environment::fromGlobals()` использует приоритет:

```text
process environment < $_SERVER < $_ENV
```

Доступны `string()`, `int()`, `float()`, `bool()`, `array()`. `ConfigPath` преобразуется в `UPPER_SNAKE_CASE`.

`env()` возвращает raw value. Typed helpers выполняют преобразование явно. `env_string()` сохраняет lexical strings вроде `001`, `true`, `false`.

## Dotenv

`EnvLoader` по умолчанию читает только `.env`, затем `.env.local`, и всегда возвращает effective runtime `Environment`.

```php
$environment = (new EnvLoader('.'))->load();
```

Deployment values имеют приоритет, пока явно не передан `override: true`. Dotenv записывается только в `$_ENV`; `$_SERVER` не изменяется. Sample/backup и остальные `.env*` файлы не подхватываются автоматически.

`read()` не изменяет globals. Required variables могут приходить как из dotenv, так и из deployment environment. Ошибки парсинга никогда не включают значения из dotenv в диагностическое сообщение.

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

`getConfig()` не может определять reserved root `dependencies`.

Replacement hooks имеют tri-state семантику: `true`/`false` — явное значение, `null` — provider не изменяет уже составленное значение.

### Merge semantics

В `dependencies`:

- `factories`, `aliases`, `services`, `parameter_resolvers` — identity maps;
- numeric `invokables` append-ятся, keyed invokables сохраняют key для canonicalization в DI v5;
- `attribute_definitions` и `attribute_capabilities` append-ятся;
- delegator pipelines append-ятся;
- поздний explicit replacement flag побеждает.

За пределами dependency root применяется обычный recursive config merge. Некорректный scalar dependency root и malformed delegator pipeline отклоняются до merge.

Callable pair delegator обязательно является вложенным pipeline item:

```php
return [
    Service::class => [
        [MetricsDelegator::class, 'decorate'],
    ],
];
```

## File providers

`FileProvider` поддерживает PHP и JSON. Файлы обрабатываются в отсортированном порядке. JSON integers за пределами platform int range сохраняются как точные строки, а не imprecise float. Ошибки чтения, парсинга и unsupported matched files приводят к fail-fast.

## Persistent application-config cache

Config cache хранит только application/package configuration. Из него намеренно исключаются:

- runtime `Environment`;
- reserved root `dependencies`, которым владеет отдельный DI v5 `DiCacheGenerator`.

```php
ConfigLoader::export($config, 'var/cache/config.php');
```

Envelope версионирован и содержит только application/package data:

```php
return [
    'version' => ConfigLoader::CACHE_VERSION,
    'config' => [
        // application/package configuration only
    ],
];
```

При загрузке передаётся текущий runtime environment:

```php
$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    Environment::fromGlobals(),
);
```

DI v5 загружает собственный dependency cache и при build снова добавляет normalized dependencies в итоговый runtime `Config`. Поэтому provider и cache modes сходятся к одной runtime shape без build-time environment и без дублирования DI graph.

Неизвестные envelope keys, embedded dependency root и stale cache version отклоняются.

## Container helpers

`ContainerValue` оборачивает PSR-11 container и использует тот же `Config` snapshot. Если `Config` не передан явно, wrapped DI v5 container обязан предоставлять `Config::class`; нарушение bootstrap contract приводит к fail-fast.

## Требования

- PHP 8.4+
- PSR-11 interfaces для container helpers
