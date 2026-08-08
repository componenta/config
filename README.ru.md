# Componenta Config

[English](README.md) | **Русский**

Иммутабельная конфигурация и доступ к окружению для библиотек Componenta.

## Установка

```bash
composer require componenta/config
```

Пакет намеренно не объявляет автоматический провайдер в `extra.componenta.config-providers`.
`Componenta\Config\ConfigProvider` — базовый класс для провайдеров пакетов и приложения.

## Требования

- PHP 8.4+
- `componenta/var-export` для экспорта файлов кеша

## Связанные пакеты

| Пакет | Зачем нужен здесь |
|---|---|
| `componenta/var-export` | Экспортирует конфигурацию в PHP-файл кеша. |
| `componenta/di` | Потребляет секцию `dependencies`, которую возвращают `ConfigProvider` классы. |
| `componenta/app` | Выбирает, загружать конфиг из провайдеров в разработке или из кеша в продакшене. |

## Что предоставляет пакет

- `Config`: иммутабельный контейнер конфигурации с буквальными ключами и `ConfigPath` ключами.
- `Environment`: иммутабельный контейнер окружения с типизированными методами чтения.
- `ConfigLoader`: загрузчик и экспортёр массивов провайдеров и файлов кеша.
- `ConfigProvider`: базовый класс модульной DI-конфигурации.
- `FileProvider`: провайдер PHP/JSON файлов с правилами merge в Componenta.
- `ContainerValue`: PSR-11-совместимая обёртка над контейнером для фабрик конфигурации, с типизированными helper-методами и прямым доступом к `Config`.
- `ContainerEntry`, `ConfigEntry`, `LazyValue`: явные value objects для typed container lookup, ссылок на другие config-ключи и ленивого вычисления значений.

## Загрузка конфигурации

`ConfigLoader` не решает, работает приложение в разработке или продакшене. Стартовый код сам выбирает, загрузить провайдеры или готовый файл кеша.

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

Кеш для продакшена:

```php
$config = ConfigLoader::loadFromFile(__DIR__ . '/var/cache/config.php', populateEnv: true);
```

Сборка кеша:

```php
ConfigLoader::export($config, __DIR__ . '/var/cache/config.php');
```

Экспортированный файл возвращает:

```php
[
    'config' => [...],
    'environment' => [...],
]
```

## Доступ к значениям

Строковые ключи считаются буквальными. `ConfigPath` ключи читают вложенные массивы.

```php
use function Componenta\Config\path;

$config->get('database.host');        // literal key: $data['database.host']
$config->get(path('database.host'));  // nested key: $data['database']['host']
```

Типизированные методы чтения конвертируют значения или бросают исключения:

```php
$host = $config->string(path('database.host'));
$port = $config->int(path('database.port'), 3306);
$debug = $config->bool(path('app.debug'), false);
$tags = $config->array(path('app.tags'), []);
```

Если значение по умолчанию не передано, отсутствующий ключ бросает `ConfigException`. Если значение нельзя привести к запрошенному типу, бросается `InvalidConfigValueException`.
Default может быть обычным значением, `config_entry(...)` или `lazy(...)`; типизированные методы сначала резолвят default, а затем проверяют итоговый тип.

## Типизированный доступ к контейнеру

`ContainerValue` оборачивает `Psr\Container\ContainerInterface` для фабрик конфигурации. Обёртка сама реализует PSR-11, поэтому старые фабрики с типом `ContainerInterface` продолжают работать, а новые фабрики фреймворка могут типизировать аргумент как `ContainerValue`, если им нужны fallback helpers или конфиг приложения.

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

`get($id)` работает как обычный PSR-11 lookup и возвращает исходную запись. `get($id, $type)` дополнительно проверяет тип найденной записи и бросает `InvalidContainerValueException`, если тип не подходит.
`find($id, $default)` возвращает существующую запись, если она есть. Если записи нет, метод возвращает default-значение, резолвит `entry(...)` из контейнера, резолвит `config_entry(...)` из `$container->config` или выполняет `lazy(...)` с текущим `ContainerValue`. Обычный callable default возвращается как callable-значение и не выполняется.

Если запись `$id` существует, а default задан через `entry(..., Type::class)`, тип из `entry()` применяется к найденной записи. Это позволяет описывать optional override и одновременно сохранять проверку типа.

## Приведение к bool

Значения true: `true`, `1`, `yes`, `on`, `enabled`, `y`.

Значения false: `false`, `0`, `no`, `off`, `disabled`, `n`, пустая строка.

Неоднозначные значения вроде `42`, `-1`, массивов, `null` или неизвестных строк не приводятся к bool молча.

## Lazy-значения

Обычные callable-значения в конфигурации считаются данными и не выполняются `Config`. Для вычисляемых значений используйте `lazy(...)`. Lazy-значения получают текущий экземпляр `Config` и по умолчанию кешируются после первого вызова. Используйте `lazy($callback, cache: false)`, если значение нужно пересчитывать при каждом чтении.

Callable defaults также являются значениями: они возвращаются как callable и не выполняются. Используйте `config_entry(...)`, когда отсутствующий ключ должен ссылаться на другой ключ конфигурации.

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

Обычные callable возвращаются без выполнения:

```php
$callable = static fn(): string => 'raw';
$config = new Config(['callback' => $callable]);

$raw = $config->get('callback'); // тот же callable
```

Отключить кеширование lazy-значения можно явно:

```php
$fresh = lazy(static fn(Config $config): string => uniqid('', true), cache: false);
```

## Environment

`EnvLoader` загружает `.env*` файлы из одной или нескольких директорий и возвращает `?Environment`.

```php
use Componenta\Config\Loader\EnvLoader;

$environment = (new EnvLoader(__DIR__))->load(
    override: false,
    populateServer: true,
);
```

Если `.env*` файлы не найдены и глобальные массивы недоступны, `load()` возвращает `null`.

Ключи окружения могут быть строками или объектами `ConfigPath`. Пути приводятся к `UPPER_SNAKE_CASE`:

```php
$environment->string('APP_ENV', 'production');
$environment->string(path('database.host')); // DATABASE_HOST
```

## ConfigProvider

Модули расширяют `ConfigProvider`, чтобы регистрировать DI-метаданные и конфигурацию модуля.
Базовый провайдер собирает итоговый массив из переопределяемых секций:

| Метод | Секция |
|---|---|
| `getProviders()` | Дочерние провайдеры, которые merge-ятся после текущего провайдера. |
| `getConfig()` | Конфигурация приложения или пакета вне `dependencies`. |
| `getFactories()` | Фабрики сервисов, где ключом является id сервиса. |
| `getInvokables()` | Классы для прямого создания; keyed-записи также создают aliases. |
| `getAliases()` | Явные aliases сервисов. |
| `getDelegators()` | Delegator-фабрики, где ключом является id декорируемого сервиса. |
| `getServices()` | Уже созданные экземпляры сервисов. |
| `getParameterResolvers()` | Пользовательские resolver-ы параметров конструктора или метода, сгруппированные по приоритету. |
| `shouldReplaceParameterResolvers()` | При значении `true` заменяет стандартную цепочку resolver-ов параметров. |
| `getAttributeHandlers()` | Обработчики runtime-атрибутов в порядке регистрации. |
| `shouldReplaceAttributeHandlers()` | При значении `true` заменяет встроенные обработчики атрибутов. |
| `getDependencyExtensions()` | Дополнительные поддерживаемые ключи DI v2, для которых нет отдельного базового метода. Неизвестные ключи и попытки заменить базовую секцию отклоняются. |

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

Вызов провайдера возвращает массив конфигурации с секцией `dependencies`.

Пустые массивы, `null` и стандартные значения `false` не записываются в эту секцию. Вложенные значения сохраняются: например, сервис со значением `false` не будет удалён. Секции v1 `autowires` и resolver-ов свойств в схему v2 не входят. Обычные конкретные классы создаются механизмом автоматического разрешения DI v2, а атрибуты обрабатываются через `getAttributeHandlers()`.

## Фабрики и aliases

Нельзя регистрировать фабрику или invokable под id, который одновременно является alias. DI сначала разрешает alias, поэтому определение под исходным id окажется недостижимым. Альтернативные реализации нужно регистрировать непосредственно под одним interface id; нужную реализацию выбирает порядок провайдеров:

```php
protected function getFactories(): array
{
    return [ServiceInterface::class => ServiceFactory::class];
}
```

Delegator нужен, когда пакет добавляет поведение поверх уже выбранной реализации. Более поздняя фабрика выбирает другую реализацию, но не отключает delegator-ы, зарегистрированные для запрошенного id.
