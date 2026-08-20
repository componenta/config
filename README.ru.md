# Componenta Config

`componenta/config` — библиотека неизменяемой runtime-конфигурации для PHP 8.4+: конфигурация приложения, runtime-снимок окружения, детерминированная композиция providers, типизированный доступ, загрузка dotenv, файловые providers и версионированный PHP-кэш.

Схема секции зависимостей рассчитана на Componenta DI v5. Config отвечает за композицию и транспорт данных; валидация и канонизация DI-определений принадлежат DI v5.

## Установка

```bash
composer require componenta/config
```

## Runtime-конфигурация

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

`Config` всегда содержит один снимок `Environment`. При прямом создании `Config` без окружения используется пустой снимок.

Целое число или строка — буквальный ключ верхнего уровня. `ConfigPath` используется для вложенного доступа:

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

`Config` реализует `Countable`, `IteratorAggregate`, read-only `ArrayAccess` и `Componenta\Arrayable\Arrayable`.

## Типизированный доступ

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$ratio = $config->float('ratio', 1.0);
$enabled = $config->bool('enabled', false);
$drivers = $config->array('drivers', []);
```

Неоднозначные и теряющие данные преобразования отклоняются. В частности, `int()` не обрезает дробную часть и не считает строку в экспоненциальной записи целым значением.

## Значения по умолчанию, ссылки и lazy values

Отсутствующий обязательный ключ приводит к `ConfigException`:

```php
$config->get('required');
```

`ConfigEntry` позволяет взять fallback из другого ключа конфигурации:

```php
use function Componenta\Config\config_entry;

$name = $config->string(
    'display_name',
    config_entry(path('app.name')),
);
```

Обычный callable является обычным значением. Явное ленивое вычисление создаётся через `LazyValue`:

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

Результат кешируется отдельно для каждого контекста `Config` или `ContainerValue`, если не задано `cache: false`.

## Environment

`Environment` — неизменяемый снимок runtime-окружения:

```php
$environment = Environment::fromGlobals();

$environment->string('APP_ENV');
$environment->bool('APP_DEBUG', false);
$environment->int('PORT', 8080);
```

Приоритет источников:

```text
process environment < $_SERVER < $_ENV
```

`ConfigPath` преобразуется в upper snake case:

```php
$environment->string(path('database.host')); // DATABASE_HOST
```

### Функции окружения

`env()` возвращает исходное значение без автоматического определения типа. Тип преобразуется только явной функцией:

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

`env_string()` сохраняет лексическое значение: `001`, `true` и `false` остаются именно такими строками.

## Загрузка dotenv

По умолчанию `EnvLoader` читает `.env`, затем `.env.local`:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load()
    ?? Environment::fromGlobals();
```

Значения окружения процесса имеют приоритет, если явно не задан `override: true`. Sample/backup-файлы автоматически не обнаруживаются — альтернативные имена необходимо перечислить явно.

`read()` только разбирает файлы. `load()` заполняет выбранные globals и возвращает итоговый runtime-снимок `Environment`.

## Загрузка конфигурации приложения

Providers компонуются в порядке передачи:

```php
use Componenta\Config\ConfigLoader;

$config = ConfigLoader::load(
    $environment,
    new AppConfigProvider(),
    new PackageConfigProvider(),
);
```

Provider может вернуть массив или iterable.

`config_merge()` использует определённую семантику:

- строковые ключи обычной конфигурации рекурсивно объединяются;
- числовые элементы обычных списков добавляются в конец;
- DI identity maps (`factories`, `aliases`, `services`, `parameter_resolvers`) атомарно заменяют значение с тем же смысловым ключом;
- list-like DI sections добавляются в порядке providers;
- числовые приоритеты parameter resolvers сохраняются и не переиндексируются;
- для replacement flags побеждает последнее явно заданное значение.

## Файловые providers

`FileProvider` по умолчанию поддерживает PHP и JSON:

```php
use Componenta\Config\FileProvider;

$provider = new FileProvider('config/*.{php,json}');
$data = $provider();
```

Файлы сортируются до merge. Неподдерживаемый matched-файл, ошибка чтения, ошибка парсинга или некорректный root завершают загрузку исключением.

Для собственного формата реализуется `Componenta\Config\Reader\FileReaderInterface`.

## ConfigProvider и DI v5

Provider пакета наследует `ConfigProvider`:

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

Поддерживаемые dependency hooks:

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

ConfigProvider намеренно не дублирует валидацию DI. Например, keyed invokables сохраняются до канонизации в DI v5. Таким образом правила DI имеют одного владельца и не расходятся между пакетами.

### Replacement flags

Replacement hooks имеют три состояния:

```php
protected function shouldReplaceParameterResolvers(): ?bool
{
    return true;
}
```

- `null` — provider не меняет ранее заданный флаг;
- `true` — встроенная цепочка заменяется;
- `false` — явно отменяется ранее заданная замена.

Для `shouldReplaceAttributeDefinitions()` действует тот же контракт.

### Delegators

Значение delegators всегда является pipeline. Callable pair является элементом pipeline и поэтому вкладывается ещё на один уровень:

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

Это делает композицию однозначной: при merge нескольких providers callable pair не может превратиться в несколько независимых delegators.

## Постоянный кэш

Persistent cache содержит только конфигурацию приложения/пакетов. `Environment` относится к runtime state и никогда не сериализуется в этот кэш.

Создание:

```php
ConfigLoader::export($config, 'var/cache/config.php');
```

Кэш имеет версионированный envelope:

```php
return [
    'version' => ConfigLoader::CACHE_VERSION,
    'config' => [
        // persistent configuration
    ],
];
```

При старте процесса текущий environment передаётся явно:

```php
$environment = Environment::fromGlobals();

$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    $environment,
);
```

Это обязательный parity-инвариант: provider mode и cache mode используют один и тот же runtime `Environment`; build-time environment не может попасть в production через config cache.

Неизвестные ключи envelope и устаревшие версии кэша отклоняются. Файл создаётся через temporary file, flush, синтаксическую проверку PHP и атомарный `rename()`.

## Container helpers

`ContainerValue` оборачивает PSR-11 container и использует тот же `Config`, что и runtime:

```php
use Componenta\Config\ContainerValue;
use function Componenta\Config\entry;

$value = new ContainerValue($container, $config);

$service = $value->get(ServiceInterface::class);
$optional = $value->find('optional.service', default: null);
$clock = $value->find('clock', entry('fallback.clock', ClockInterface::class));
```

## Ошибки

Ошибки конфигурации реализуют `Componenta\Config\Exception\ConfigExceptionInterface`.

- `ConfigException` — отсутствие ключей, ошибки provider/cache/file;
- `InvalidConfigValueException` — ошибка типизированного преобразования;
- `InvalidContainerValueException` — несовместимый тип container entry;
- `EnvLoaderException` — ошибка чтения/парсинга dotenv или required variables.

## Требования

- PHP 8.4+
- PSR-11 interfaces для container helpers
