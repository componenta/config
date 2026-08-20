# Componenta Config

`componenta/config` — библиотека конфигурации для PHP 8.4+. Она предоставляет неизменяемые снимки конфигурации, явные вложенные пути, типизированное чтение, загрузку окружения, файловые провайдеры, детерминированное объединение конфигурации, ленивые значения и генерацию PHP-кеша.

Пакет не зависит от фреймворка. Его можно использовать отдельно или как слой конфигурации для DI-контейнера.

## Установка

```bash
composer require componenta/config
```

## Быстрый старт

Создайте конфигурацию из массива:

```php
use Componenta\Config\Config;
use function Componenta\Config\path;

$config = new Config([
    'app' => [
        'name' => 'Example',
        'debug' => false,
    ],
]);

$name = $config->string(path('app.name'));
$debug = $config->bool(path('app.debug'));
```

Обычная строка всегда означает **буквальный ключ**. Для вложенного доступа используется `ConfigPath`:

```php
$config = new Config([
    'database.host' => 'literal-key',
    'database' => [
        'host' => 'localhost',
    ],
]);

$config->get('database.host');       // literal-key
$config->get(path('database.host')); // localhost
```

Так ключи, содержащие точку, не конфликтуют с синтаксисом вложенных путей.

## Типизированное чтение

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$ratio = $config->float('ratio', 1.0);
$enabled = $config->bool('enabled', false);
$drivers = $config->array('drivers', []);
```

Если значение нельзя корректно преобразовать, выбрасывается `InvalidConfigValueException`. Библиотека не скрывает ошибку неявным приведением к случайному значению.

`get()` возвращает значение без преобразования:

```php
$value = $config->get('key');
$value = $config->get('optional', 'fallback');
```

Если ключ обязателен, не передавайте default — при его отсутствии будет выброшен `ConfigException`.

## Пути

```php
use Componenta\Config\ConfigPath;
use function Componenta\Config\path;

$path = new ConfigPath('database.connections.primary');
$path = path('database.connections.primary');

$path->toArray();  // ['database', 'connections', 'primary']
$path->first();    // database
$path->last();     // primary
$path->isNested(); // true
```

## Значения по умолчанию и ссылки

Обычный default:

```php
$timeout = $config->int('timeout', 30);
```

Если fallback должен браться из другого ключа конфигурации, используйте `ConfigEntry`:

```php
use function Componenta\Config\config_entry;
use function Componenta\Config\path;

$name = $config->string(
    'display_name',
    config_entry(path('app.name')),
);
```

## Ленивые значения

Обычные callable хранятся как значения и автоматически не выполняются.

Для явного ленивого вычисления используйте `lazy()`:

```php
use Componenta\Config\Config;
use function Componenta\Config\lazy;

$config = new Config([
    'host' => 'localhost',
    'dsn' => lazy(
        static fn (Config $config): string =>
            'mysql:host=' . $config->string('host'),
    ),
]);

$dsn = $config->string('dsn');
```

По умолчанию результат кешируется **отдельно для каждого `Config` или `ContainerValue`**. Один и тот же wrapper можно безопасно использовать в нескольких снимках конфигурации — результат одного контекста не попадет в другой.

Кеш можно отключить:

```php
$value = lazy(
    static fn (): int => random_int(1, 100),
    cache: false,
);
```

## Фильтрация

`Config` неизменяем. `only()` и `except()` создают новый объект:

```php
$public = $config->only([
    'app',
    path('database.host'),
]);

$withoutSecrets = $config->except([
    path('database.password'),
]);
```

Исходный объект не изменяется.

Также `Config` реализует `Countable`, `IteratorAggregate`, `ArrayAccess` для чтения и `Componenta\Arrayable\Arrayable`.

## Переменные окружения

### Environment

```php
use Componenta\Config\Environment;

$environment = new Environment([
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
]);

$mode = $environment->string('APP_ENV');
$debug = $environment->bool('APP_DEBUG');
```

Снимок текущего окружения:

```php
$environment = Environment::fromGlobals();
```

Приоритет источников:

```text
process environment < $_SERVER < $_ENV
```

`ConfigPath` преобразуется в `UPPER_SNAKE_CASE`:

```php
$environment->string(path('database.host')); // DATABASE_HOST
```

### Загрузка `.env`

По умолчанию `EnvLoader` читает только `.env`, затем `.env.local`:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load();
```

`.env.local` перекрывает значения из `.env`.

Значения, уже заданные окружением приложения, имеют приоритет. Явное перекрытие:

```php
$environment = (new EnvLoader('.'))->load(override: true);
```

Файлы `.env.example`, резервные копии и другие совпадения `.env*` автоматически не загружаются. Другой набор задается явно:

```php
$loader = new EnvLoader(
    '.',
    filenames: ['.env', '.env.production'],
);
```

Обязательные переменные:

```php
$loader = new EnvLoader(
    '.',
    required: ['APP_KEY', 'DATABASE_URL'],
);
```

Они могут быть определены как в загруженных файлах, так и окружением развертывания.

`read()` только разбирает файлы и не изменяет `$_ENV`/`$_SERVER`.

### Функции окружения

```php
use function Componenta\Config\env;
use function Componenta\Config\env_array;
use function Componenta\Config\env_bool;
use function Componenta\Config\env_float;
use function Componenta\Config\env_int;
use function Componenta\Config\env_string;

$name = env_string('APP_NAME', 'Example');
$port = env_int('PORT', 8080);
$debug = env_bool('APP_DEBUG', false);
$ratio = env_float('RATIO', 1.0);
$hosts = env_array('HOSTS', []);
$value = env('CUSTOM_VALUE');
```

Типизированные функции преобразуют исходное значение environment variable напрямую и выбрасывают исключение, если безопасное преобразование невозможно. Только общий `env()` автоматически определяет boolean и numeric значения.

## Конфигурация из файлов

`FileProvider` по умолчанию поддерживает PHP и JSON:

```php
use Componenta\Config\FileProvider;

$provider = new FileProvider('config/*.{php,json}');
$data = $provider();
```

Файлы сортируются по пути и объединяются последовательно.

PHP-файл обязан возвращать массив:

```php
<?php

return [
    'app' => [
        'name' => 'Example',
    ],
];
```

Корень JSON должен быть object или array.

Любой файл, попавший под pattern, считается конфигурационным. Нечитаемый файл, неверный формат, неподдерживаемое расширение или некорректный root приводят к `ConfigException`, а не молча игнорируются.

### Свой reader

```php
use Componenta\Config\Reader\FileReaderInterface;

final class IniReader implements FileReaderInterface
{
    public function readFile(string $file): ?array
    {
        if (!str_ends_with($file, '.ini')) {
            return null;
        }

        $data = parse_ini_file($file, true);

        if ($data === false) {
            throw new RuntimeException('Invalid INI configuration.');
        }

        return $data;
    }
}
```

`null` означает только «этот reader не поддерживает формат». Ошибка чтения или разбора поддерживаемого файла должна завершаться исключением.

```php
$provider = new FileProvider(
    'config/*.ini',
    readers: [new IniReader()],
);
```

## Объединение providers

```php
use Componenta\Config\ConfigLoader;

$config = ConfigLoader::load(
    $environment,
    static fn (): array => require 'config/app.php',
    static fn (): array => require 'config/local.php',
);
```

Более поздний provider перекрывает scalar/map значения. Обычные numeric arrays добавляются в конец.

Прямое объединение:

```php
use function Componenta\Config\config_merge;

$merged = config_merge(
    ['middleware' => ['auth']],
    ['middleware' => ['csrf']],
);

// ['middleware' => ['auth', 'csrf']]
```

## ConfigProvider для пакетов

`ConfigProvider` позволяет пакету публиковать настройки приложения и metadata для контейнера зависимостей:

```php
use Componenta\Config\ConfigProvider;

final class PackageConfigProvider extends ConfigProvider
{
    protected function getConfig(): array
    {
        return [
            'feature' => [
                'enabled' => true,
            ],
        ];
    }

    protected function getFactories(): array
    {
        return [
            ServiceInterface::class => ServiceFactory::class,
        ];
    }

    protected function getAliases(): array
    {
        return [
            LoggerInterface::class => AppLogger::class,
        ];
    }
}
```

Доступные hooks:

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
getDependencyExtensions()
```

Дочерние providers:

```php
protected function getProviders(): iterable
{
    return [
        new DatabaseConfigProvider(),
        new CacheConfigProvider(),
    ];
}
```

### Свои parameter resolvers

Ключ массива — priority, поэтому он сохраняется при композиции:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => RequestResolver::class,
        500 => TenantResolver::class,
    ];
}
```

Если более поздний provider использует тот же priority, resolver заменяется целиком.

Полная замена стандартной цепочки:

```php
protected function shouldReplaceParameterResolvers(): bool
{
    return true;
}
```

### Attribute definitions и capabilities

Контейнеры с композицией атрибутов могут получать определения через provider:

```php
protected function getAttributeDefinitions(): array
{
    return [
        new AttributeDefinition(
            attribute: FromTenant::class,
            handler: new FromTenantHandler(),
        ),
    ];
}

protected function getAttributeCapabilities(): array
{
    return [
        new CapabilityPolicy(ValueProvider::class, maxPerTarget: 1),
    ];
}
```

Полная замена встроенных definitions:

```php
protected function shouldReplaceAttributeDefinitions(): bool
{
    return true;
}
```

Конкретные классы `AttributeDefinition`, `CapabilityPolicy` и handlers принадлежат контейнеру. `componenta/config` только передает и корректно объединяет эти значения.

### Container-specific extensions

Если контейнеру нужна дополнительная metadata:

```php
protected function getDependencyExtensions(): array
{
    return [
        'container_specific_option' => [
            'enabled' => true,
        ],
    ];
}
```

Extension не может подменить стандартную секцию. Проверка container-specific ключей выполняется потребителем.

## Правила merge для dependencies

Для корневой секции `dependencies` используются специальные правила:

- `factories`, `aliases`, `services`, `parameter_resolvers` — карты с идентичностью ключа; совпавший id/priority заменяется атомарно.
- `invokables`, `attribute_definitions`, `attribute_capabilities` — списки; элементы добавляются в порядке providers.
- delegators одного сервиса добавляются в порядке providers.
- replace-флаги — scalar; выигрывает более поздний provider.
- остальная конфигурация объединяется обычным рекурсивным алгоритмом.

Так priorities не переиндексируются, а factory/service definitions не склеиваются как обычные вложенные массивы.

## PHP-кеш

Экспорт:

```php
use Componenta\Config\ConfigLoader;

ConfigLoader::export(
    $config,
    'var/cache/config.php',
);
```

Загрузка:

```php
$config = ConfigLoader::loadFromFile(
    'var/cache/config.php',
    populateEnv: true,
);
```

Кеш полностью записывается во временный файл, сбрасывается на диск, проходит PHP lint и только после этого активируется через `rename()`. Если предыдущий artifact находится в OPcache, старый opcode инвалидируется до замены файла.

Данные должны поддерживаться `componenta/var-export`. Runtime-only объекты, например closures, перед постоянным кешированием нужно вычислить или исключить.

## ContainerValue

`ContainerValue` оборачивает любой PSR-11 контейнер:

```php
use Componenta\Config\ContainerValue;

$value = new ContainerValue($container, $config);

$service = $value->get(ServiceInterface::class);
$optional = $value->find('optional.service', default: null);
```

Fallback на другой container entry:

```php
use function Componenta\Config\entry;

$clock = $value->find(
    'clock',
    entry('fallback.clock', ClockInterface::class),
);
```

Fallback на конфигурацию:

```php
use function Componenta\Config\config_entry;
use function Componenta\Config\path;

$name = $value->find(
    'display_name',
    config_entry(path('app.name')),
);
```

## Ошибки

Общая граница — `Componenta\Config\Exception\ConfigExceptionInterface`.

Основные исключения:

- `ConfigException` — отсутствующий ключ, неправильный provider, ошибки файлов и кеша.
- `InvalidConfigValueException` — невозможно выполнить типизированное преобразование.
- `InvalidContainerValueException` — container entry не соответствует ожидаемому типу.
- `EnvLoaderException` — ошибка чтения/разбора dotenv или отсутствует required variable.

## Лицензия

MIT.
