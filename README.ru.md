# Componenta Config

`componenta/config` — конфигурационный слой для Componenta DI v5. Пакет предоставляет неизменяемую runtime-конфигурацию, снимок окружения, детерминированную композицию providers, типизированное чтение, dotenv loader, файловые providers и версионированный PHP-кэш для PHP 8.4+.

В пакете нет режима development/production. Приложение само выбирает источник данных — providers или постоянный cache; семантика итоговых `Config` и `Environment` остаётся одинаковой.

## Установка

```bash
composer require componenta/config
```

## Runtime-модель

`Config` объединяет постоянные данные приложения/пакетов и ровно один runtime-снимок `Environment`:

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

Если `Config` создаётся без явно переданного окружения, используется пустой `Environment`; свойство никогда не nullable.

Целые числа и строки являются буквальными ключами верхнего уровня. `ConfigPath` выполняет вложенный lookup:

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

`Config` неизменяем и реализует `Countable`, `IteratorAggregate`, read-only `ArrayAccess` и `Componenta\Arrayable\Arrayable`.

## Типизированные значения

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$ratio = $config->float('ratio', 1.0);
$enabled = $config->bool('enabled', false);
$drivers = $config->array('drivers', []);
```

Преобразование в `int` строгое: дробные значения, строки в экспоненциальной записи и значения за пределами диапазона платформенного integer отклоняются, а не обрезаются или насыщаются.

## Значения по умолчанию и lazy values

Отсутствующий ключ без default приводит к `ConfigException`.

`ConfigEntry` позволяет получить fallback из другого ключа конфигурации:

```php
use function Componenta\Config\config_entry;

$name = $config->string(
    'display_name',
    config_entry(path('app.name')),
);
```

Обычный callable остаётся обычным значением. Явное ленивое вычисление создаётся через `LazyValue`:

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

Результат кешируется отдельно для каждого `Config` или `ContainerValue`, если не задано `cache: false`.

## Runtime environment

`Environment` — неизменяемый снимок окружения. `Environment::fromGlobals()` использует приоритет:

```text
process environment < $_SERVER < $_ENV
```

Доступны типизированные методы:

```php
$environment->string('APP_ENV');
$environment->bool('APP_DEBUG', false);
$environment->int('PORT', 8080);
```

`ConfigPath` преобразуется в upper snake case:

```php
$environment->string(path('database.host')); // DATABASE_HOST
```

### Environment helpers

`env()` возвращает исходное значение без автоматического определения типа. Преобразование выполняется только явной typed-функцией:

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

`env_string()` сохраняет лексическое значение строк `001`, `true` и `false`.

## Загрузка dotenv

`EnvLoader` читает `.env`, затем `.env.local` и всегда возвращает итоговый runtime `Environment`:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load();
```

Существующие deployment-значения имеют приоритет, если явно не указан `override: true`:

```php
$environment = (new EnvLoader('.'))->load(override: true);
```

`read()` только разбирает файлы. `load()` записывает dotenv-значения в `$_ENV` и не зеркалирует их в `$_SERVER`. При этом существующие `$_SERVER` и process environment остаются допустимыми runtime-источниками.

Sample, backup и другие `.env*` файлы автоматически не обнаруживаются. Альтернативные имена перечисляются явно:

```php
$environment = (new EnvLoader(
    '.',
    filenames: ['.env', '.env.production'],
))->load();
```

Required values могут приходить как из dotenv, так и из deployment environment:

```php
$environment = (new EnvLoader(
    '.',
    required: ['APP_KEY', 'DATABASE_URL'],
))->load();
```

## Загрузка конфигурации

Providers объединяются в порядке передачи:

```php
use Componenta\Config\ConfigLoader;

$config = ConfigLoader::load(
    $environment,
    new AppConfigProvider(),
    new PackageConfigProvider(),
);
```

Provider может вернуть массив или iterable.

`config_merge()` использует детерминированные правила, согласованные с DI v5:

- обычные строковые ключи рекурсивно объединяются;
- числовые элементы обычных списков добавляются в конец;
- `factories`, `aliases`, `services` и `parameter_resolvers` являются identity maps и атомарно заменяют одинаковый смысловой ключ;
- integer priorities parameter resolvers сохраняются и никогда не переиндексируются;
- `invokables`, `attribute_definitions` и `attribute_capabilities` добавляются в порядке providers;
- delegators компонуются как pipelines;
- для replacement flags побеждает последнее явно заданное значение.

## ConfigProvider и DI v5

`ConfigProvider` содержит только dependency sections, которые потребляет DI v5:

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

`getConfig()` не может определять зарезервированный root key `dependencies`.

Config проверяет только shapes, необходимые для безопасной композиции. Семантическая валидация и канонизация factories, aliases, invokables, resolvers и attribute definitions принадлежит только DI v5.

### Replacement flags

Replacement hooks имеют три состояния:

```php
protected function shouldReplaceParameterResolvers(): ?bool
{
    return true;
}
```

- `null` — provider не меняет ранее заданное значение;
- `true` — встроенная цепочка заменяется;
- `false` — явно отменяется ранее заданный replace.

Тот же контракт используется `shouldReplaceAttributeDefinitions()`.

### Delegators

Значение delegator всегда является pipeline list. Callable pair должна быть вложена как один элемент pipeline:

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

Прямая callable pair `[MetricsDelegator::class, 'decorate']` отклоняется, потому что иначе её смысл мог бы измениться после merge нескольких providers.

## Файловые providers

`FileProvider` по умолчанию поддерживает PHP и JSON:

```php
use Componenta\Config\FileProvider;

$data = (new FileProvider('config/*.{php,json}'))();
```

Matched files обрабатываются в отсортированном порядке. Неподдерживаемый файл, ошибка чтения/парсинга или некорректный root приводят к fail-fast.

Для собственного формата реализуется `Componenta\Config\Reader\FileReaderInterface`.

## Постоянный cache

Persistent cache содержит только конфигурацию приложения/пакетов. Runtime `Environment` никогда в него не сериализуется.

Создание:

```php
ConfigLoader::export($config, 'var/cache/config.php');
```

Envelope версионирован:

```php
return [
    'version' => ConfigLoader::CACHE_VERSION,
    'config' => [
        // persistent configuration
    ],
];
```

При старте передаётся текущее runtime environment:

```php
$environment = Environment::fromGlobals();

$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    $environment,
);
```

Provider mode и cache mode используют одинаковую семантику runtime environment, поэтому build-time secrets не могут попасть в production через config cache.

Неизвестные ключи envelope и устаревшие версии кэша отклоняются. Cache-файл создаётся через temporary file, flush, PHP syntax check и атомарный `rename()`.

## Container helpers

`ContainerValue` оборачивает PSR-11 container и использует тот же runtime `Config`:

```php
use Componenta\Config\ContainerValue;
use function Componenta\Config\entry;

$value = new ContainerValue($container, $config);

$service = $value->get(ServiceInterface::class);
$optional = $value->find('optional.service', default: null);
$clock = $value->find('clock', entry('fallback.clock', ClockInterface::class));
```

## Требования

- PHP 8.4+
- PSR-11 interfaces для container helpers
