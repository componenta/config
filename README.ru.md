# Componenta Config

`componenta/config` — библиотека конфигурации для PHP 8.4+. Она предоставляет неизменяемые снимки конфигурации, явные вложенные пути, типизированное чтение, загрузку окружения, файловые провайдеры, детерминированное объединение конфигурации, ленивые значения и генерацию PHP-кеша.

Пакет не зависит от фреймворка. Его можно использовать отдельно или как слой конфигурации для DI-контейнера.

## Установка

```bash
composer require componenta/config
```

## Быстрый старт

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
    'database' => ['host' => 'localhost'],
]);

$config->get('database.host');       // literal-key
$config->get(path('database.host')); // localhost
```

Так ключи с точкой не конфликтуют с синтаксисом вложенных путей.

## Типизированное чтение

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$ratio = $config->float('ratio', 1.0);
$enabled = $config->bool('enabled', false);
$drivers = $config->array('drivers', []);
```

Если значение нельзя корректно преобразовать, выбрасывается `InvalidConfigValueException`.

`get()` возвращает значение без преобразования. Если ключ обязателен, не передавайте default — при отсутствии будет выброшен `ConfigException`.

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

## Defaults и ссылки на другие ключи

```php
$timeout = $config->int('timeout', 30);
```

Fallback из другого ключа:

```php
use function Componenta\Config\config_entry;
use function Componenta\Config\path;

$name = $config->string(
    'display_name',
    config_entry(path('app.name')),
);
```

## Ленивые значения

Обычный callable хранится как значение и автоматически не выполняется.

Явное ленивое вычисление:

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

Результат кешируется **отдельно для каждого `Config` или `ContainerValue`**. Один wrapper можно безопасно использовать в нескольких снимках конфигурации.

Отключение кеша:

```php
$value = lazy(
    static fn (): int => random_int(1, 100),
    cache: false,
);
```

## Фильтрация

`Config` неизменяем. `only()` и `except()` возвращают новый объект:

```php
$public = $config->only([
    'app',
    path('database.host'),
]);

$withoutSecrets = $config->except([
    path('database.password'),
]);
```

Исходный объект не изменяется. `Config` также реализует `Countable`, `IteratorAggregate`, read-only `ArrayAccess` и `Componenta\Arrayable\Arrayable`.

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

Приоритет:

```text
process environment < $_SERVER < $_ENV
```

`ConfigPath` преобразуется в `UPPER_SNAKE_CASE`:

```php
$environment->string(path('database.host')); // DATABASE_HOST
```

### Загрузка `.env`

По умолчанию `EnvLoader` читает `.env`, затем `.env.local`:

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader('.'))->load();
```

`.env.local` перекрывает `.env`. Значения окружения развертывания имеют приоритет, пока override не включен явно:

```php
$environment = (new EnvLoader('.'))->load(override: true);
```

`.env.example`, backup-файлы и другие `.env*` автоматически не загружаются. Другие имена задаются явно:

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

Они могут находиться как в файлах, так и в окружении развертывания.

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

Типизированные функции не выполняют небезопасное молчаливое преобразование.

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

Любой файл, попавший под pattern, считается конфигурацией. Нечитаемый или поврежденный файл, неподдерживаемое расширение и неправильный root приводят к `ConfigException`, а не игнорируются.

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

`null` означает только «reader не поддерживает этот формат». Если расширение поддерживается, ошибка чтения или разбора должна завершаться исключением.

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

```php
use function Componenta\Config\config_merge;

$merged = config_merge(
    ['middleware' => ['auth']],
    ['middleware' => ['csrf']],
);

// ['middleware' => ['auth', 'csrf']]
```

## ConfigProvider для пакетов

`ConfigProvider` публикует настройки приложения и metadata для DI-контейнера:

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

Ключ массива — priority и сохраняется при объединении:

```php
protected function getParameterResolvers(): array
{
    return [
        900 => RequestResolver::class,
        500 => TenantResolver::class,
    ];
}
```

При совпадении priority более поздний resolver заменяет предыдущий целиком.

Полная замена стандартной цепочки:

```php
protected function shouldReplaceParameterResolvers(): bool
{
    return true;
}
```

### Attribute definitions и capabilities

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

Конкретные `AttributeDefinition`, `CapabilityPolicy` и handlers принадлежат контейнеру. `componenta/config` только передает и корректно объединяет значения.

### Container-specific extensions

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

Extension не может заменить стандартную секцию. Проверку специальных ключей выполняет потребитель.

## Merge semantics для dependencies

- `factories`, `aliases`, `services`, `parameter_resolvers` — identity maps; совпавший id/priority заменяется атомарно.
- `invokables`, `attribute_definitions`, `attribute_capabilities` — списки; элементы добавляются в порядке providers.
- delegators одного сервиса добавляются в порядке providers.
- replace-флаги — scalar; выигрывает более поздний provider.
- остальная конфигурация объединяется обычным рекурсивным алгоритмом.

Так resolver priorities не переиндексируются, а factory/service definitions не склеиваются как вложенные массивы.

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

Кеш сначала полностью записывается во временный файл и только затем активируется через `rename()`, поэтому читатель не получает частично записанный PHP.

Данные должны поддерживаться `componenta/var-export`. Runtime-only объекты, например closures, перед постоянным кешированием нужно вычислить или исключить.

## ContainerValue

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

- `ConfigException` — отсутствующий ключ, неверный provider, ошибки файлов и кеша.
- `InvalidConfigValueException` — невозможно выполнить типизированное преобразование.
- `InvalidContainerValueException` — container entry не соответствует ожидаемому типу.
- `EnvLoaderException` — ошибка dotenv или отсутствует required variable.

## Требования

- PHP 8.4+
- PSR-11 для container helpers
