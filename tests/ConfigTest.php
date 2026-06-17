<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigValueException;
use Componenta\Config\ConfigPath;
use Componenta\Config\LazyValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    // =========================================================================
    // GET - Basic Access
    // =========================================================================

    public function testGetReturnsValueForLiteralKey(): void
    {
        $config = new Config(['database.host' => 'localhost']);

        self::assertSame('localhost', $config->get('database.host'));
    }

    public function testGetReturnsNestedValueForPath(): void
    {
        $config = new Config([
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
            ],
        ]);

        self::assertSame('localhost', $config->get(new ConfigPath('database.host')));
        self::assertSame(3306, $config->get(new ConfigPath('database.port')));
    }

    public function testGetReturnsDeeplyNestedValue(): void
    {
        $config = new Config([
            'services' => [
                'database' => [
                    'connections' => [
                        'mysql' => ['host' => 'db.example.com'],
                    ],
                ],
            ],
        ]);

        self::assertSame(
            'db.example.com',
            $config->get(new ConfigPath('services.database.connections.mysql.host')),
        );
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $config = new Config([]);

        self::assertSame('default', $config->get('missing', 'default'));
        self::assertSame('default', $config->get(new ConfigPath('missing.key'), 'default'));
    }

    public function testGetThrowsWhenKeyMissingAndNoDefault(): void
    {
        $config = new Config([]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Configuration key 'missing' is missing");

        $config->get('missing');
    }

    public function testGetThrowsForMissingNestedPath(): void
    {
        $config = new Config(['database' => []]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Configuration key 'database.host' is missing");

        $config->get(new ConfigPath('database.host'));
    }

    // =========================================================================
    // HAS
    // =========================================================================

    public function testHasReturnsTrueForExistingLiteralKey(): void
    {
        $config = new Config(['key' => 'value']);

        self::assertTrue($config->has('key'));
    }

    public function testHasReturnsTrueForExistingPath(): void
    {
        $config = new Config(['database' => ['host' => 'localhost']]);

        self::assertTrue($config->has(new ConfigPath('database.host')));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $config = new Config([]);

        self::assertFalse($config->has('missing'));
        self::assertFalse($config->has(new ConfigPath('missing.path')));
    }

    public function testHasReturnsTrueForNullValue(): void
    {
        $config = new Config(['nullable' => null]);

        self::assertTrue($config->has('nullable'));
    }

    // =========================================================================
    // TYPED ACCESS - string()
    // =========================================================================

    public function testStringReturnsStringValue(): void
    {
        $config = new Config(['name' => 'application']);

        self::assertSame('application', $config->string('name'));
    }

    public function testStringConvertsScalarToString(): void
    {
        $config = new Config([
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
        ]);

        self::assertSame('42', $config->string('int'));
        self::assertSame('3.14', $config->string('float'));
        self::assertSame('1', $config->string('bool'));
    }

    public function testStringReturnsDefaultWhenMissing(): void
    {
        $config = new Config([]);

        self::assertSame('default', $config->string('missing', 'default'));
    }

    public function testStringThrowsWhenCannotConvert(): void
    {
        $config = new Config(['array' => ['a', 'b']]);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage("Cannot convert configuration key 'array' to string");

        $config->string('array');
    }

    // =========================================================================
    // TYPED ACCESS - int()
    // =========================================================================

    public function testIntReturnsIntegerValue(): void
    {
        $config = new Config(['port' => 3306]);

        self::assertSame(3306, $config->int('port'));
    }

    public function testIntConvertsNumericString(): void
    {
        $config = new Config(['port' => '3306']);

        self::assertSame(3306, $config->int('port'));
    }

    public function testIntConvertsBoolToInt(): void
    {
        $config = new Config(['enabled' => true, 'disabled' => false]);

        self::assertSame(1, $config->int('enabled'));
        self::assertSame(0, $config->int('disabled'));
    }

    public function testIntReturnsDefaultWhenMissing(): void
    {
        $config = new Config([]);

        self::assertSame(8080, $config->int('port', 8080));
    }

    public function testIntThrowsWhenCannotConvert(): void
    {
        $config = new Config(['value' => 'not-a-number']);

        $this->expectException(InvalidConfigValueException::class);
        $this->expectExceptionMessage("Cannot convert configuration key 'value' to int");

        $config->int('value');
    }

    // =========================================================================
    // TYPED ACCESS - float()
    // =========================================================================

    public function testFloatReturnsFloatValue(): void
    {
        $config = new Config(['rate' => 0.5]);

        self::assertSame(0.5, $config->float('rate'));
    }

    public function testFloatConvertsIntToFloat(): void
    {
        $config = new Config(['value' => 42]);

        self::assertSame(42.0, $config->float('value'));
    }

    public function testFloatConvertsNumericString(): void
    {
        $config = new Config(['rate' => '3.14']);

        self::assertSame(3.14, $config->float('rate'));
    }

    public function testFloatThrowsWhenCannotConvert(): void
    {
        $config = new Config(['value' => 'not-a-number']);

        $this->expectException(InvalidConfigValueException::class);

        $config->float('value');
    }

    // =========================================================================
    // TYPED ACCESS - bool()
    // =========================================================================

    #[DataProvider('boolConversionProvider')]
    public function testBoolConvertsValues(mixed $input, bool $expected): void
    {
        $config = new Config(['value' => $input]);

        self::assertSame($expected, $config->bool('value'));
    }

    public static function boolConversionProvider(): iterable
    {
        // Native booleans
        yield 'true' => [true, true];
        yield 'false' => [false, false];

        // Truthy strings
        yield 'string true' => ['true', true];
        yield 'string TRUE' => ['TRUE', true];
        yield 'string 1' => ['1', true];
        yield 'string yes' => ['yes', true];
        yield 'string on' => ['on', true];
        yield 'string enabled' => ['enabled', true];
        yield 'string y' => ['y', true];

        // Falsy strings
        yield 'string false' => ['false', false];
        yield 'string FALSE' => ['FALSE', false];
        yield 'string 0' => ['0', false];
        yield 'string no' => ['no', false];
        yield 'string off' => ['off', false];
        yield 'string disabled' => ['disabled', false];
        yield 'string n' => ['n', false];
        yield 'empty string' => ['', false];

        // Numbers
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
    }

    // =========================================================================
    // TYPED ACCESS - array()
    // =========================================================================

    public function testArrayReturnsArrayValue(): void
    {
        $config = new Config(['items' => ['a', 'b', 'c']]);

        self::assertSame(['a', 'b', 'c'], $config->array('items'));
    }

    public function testArraySplitsCommaSeparatedString(): void
    {
        $config = new Config(['drivers' => 'redis, memcached, file']);

        self::assertSame(['redis', 'memcached', 'file'], $config->array('drivers'));
    }

    public function testArrayReturnsEmptyArrayForEmptyString(): void
    {
        $config = new Config(['empty' => '']);

        self::assertSame([], $config->array('empty'));
    }

    public function testArrayWrapsScalarValue(): void
    {
        $config = new Config(['single' => 'value']);

        self::assertSame(['value'], $config->array('single'));
    }

    // =========================================================================
    // LAZY VALUES
    // =========================================================================

    public function testLazyValueIsExecuted(): void
    {
        $config = new Config([
            'computed' => new LazyValue(static fn(Config $config): string => 'result'),
        ]);

        self::assertSame('result', $config->get('computed'));
    }

    public function testLazyValueReceivesConfigInstance(): void
    {
        $config = new Config([
            'host' => 'localhost',
            'port' => 3306,
            'dsn' => new LazyValue(
                static fn(Config $config): string => "mysql:host={$config->string('host')};port={$config->int('port')}",
            ),
        ]);

        self::assertSame('mysql:host=localhost;port=3306', $config->string('dsn'));
    }

    public function testLazyValueResultIsCached(): void
    {
        $counter = 0;

        $config = new Config([
            'value' => new LazyValue(function () use (&$counter): string {
                $counter++;
                return 'result';
            }),
        ]);

        $config->get('value');
        $config->get('value');
        $config->get('value');

        self::assertSame(1, $counter);
    }

    public function testLazyValueResultCanDisableCache(): void
    {
        $counter = 0;

        $config = new Config([
            'value' => new LazyValue(function () use (&$counter): string {
                $counter++;
                return 'result';
            }, cache: false),
        ]);

        $config->get('value');
        $config->get('value');
        $config->get('value');

        self::assertSame(3, $counter);
    }

    public function testCallableDefaultIsReturnedAsValue(): void
    {
        $config = new Config([]);
        $callable = fn() => 'should-not-execute';

        $result = $config->get('missing', $callable);

        self::assertSame($callable, $result);
    }

    public function testConfigEntryDefaultIsResolvedWhenKeyMissing(): void
    {
        $config = new Config([
            'fallback' => [
                'name' => 'Componenta',
            ],
        ]);

        $result = $config->get('missing', new ConfigEntry(new ConfigPath('fallback.name')));

        self::assertSame('Componenta', $result);
    }

    public function testTypedAccessorsResolveConfigEntryDefault(): void
    {
        $config = new Config([
            'fallback' => [
                'name' => 'Componenta',
            ],
        ]);

        $result = $config->string('missing', new ConfigEntry(new ConfigPath('fallback.name')));

        self::assertSame('Componenta', $result);
    }

    public function testTypedAccessorsResolveLazyDefault(): void
    {
        $config = new Config([
            'fallback' => [
                'name' => 'Componenta',
            ],
        ]);

        $result = $config->string(
            'missing',
            new LazyValue(static fn(Config $config): string => $config->string(new ConfigPath('fallback.name'))),
        );

        self::assertSame('Componenta', $result);
    }

    public function testCallableValueIsReturnedAsValue(): void
    {
        $callable = fn() => 'should-not-execute';
        $config = new Config(['value' => $callable]);

        $result = $config->get('value');

        self::assertSame($callable, $result);
    }

    // =========================================================================
    // FILTERING - only() / except()
    // =========================================================================

    public function testOnlyReturnsSubsetWithLiteralKeys(): void
    {
        $config = new Config([
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        $filtered = $config->only(['a', 'c']);

        self::assertSame(['a' => 1, 'c' => 3], $filtered->toArray());
    }

    public function testOnlyReturnsSubsetWithPaths(): void
    {
        $config = new Config([
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'password' => 'secret',
            ],
        ]);

        $filtered = $config->only([
            new ConfigPath('database.host'),
            new ConfigPath('database.port'),
        ]);

        self::assertSame([
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
            ],
        ], $filtered->toArray());
    }

    public function testOnlyIgnoresMissingKeys(): void
    {
        $config = new Config(['a' => 1]);

        $filtered = $config->only(['a', 'missing']);

        self::assertSame(['a' => 1], $filtered->toArray());
    }

    public function testExceptReturnsSubsetWithoutLiteralKeys(): void
    {
        $config = new Config([
            'a' => 1,
            'b' => 2,
            'c' => 3,
        ]);

        $filtered = $config->except(['b']);

        self::assertSame(['a' => 1, 'c' => 3], $filtered->toArray());
    }

    public function testExceptReturnsSubsetWithoutPaths(): void
    {
        $config = new Config([
            'database' => [
                'host' => 'localhost',
                'password' => 'secret',
            ],
        ]);

        $filtered = $config->except(new ConfigPath('database.password'));

        self::assertSame([
            'database' => [
                'host' => 'localhost',
            ],
        ], $filtered->toArray());
    }

    // =========================================================================
    // ARRAY ACCESS
    // =========================================================================

    public function testOffsetGetReturnsValue(): void
    {
        $config = new Config(['key' => 'value']);

        self::assertSame('value', $config['key']);
    }

    public function testOffsetGetWorksWithPath(): void
    {
        $config = new Config(['database' => ['host' => 'localhost']]);

        self::assertSame('localhost', $config[new ConfigPath('database.host')]);
    }

    public function testOffsetExistsReturnsTrueForExistingKey(): void
    {
        $config = new Config(['key' => 'value']);

        self::assertTrue(isset($config['key']));
    }

    public function testOffsetExistsReturnsFalseForMissingKey(): void
    {
        $config = new Config([]);

        self::assertFalse(isset($config['missing']));
    }

    public function testOffsetSetThrowsException(): void
    {
        $config = new Config([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config is immutable');

        $config['key'] = 'value';
    }

    public function testOffsetUnsetThrowsException(): void
    {
        $config = new Config(['key' => 'value']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config is immutable');

        unset($config['key']);
    }

    // =========================================================================
    // COUNTABLE / ITERABLE
    // =========================================================================

    public function testCountReturnsTopLevelCount(): void
    {
        $config = new Config([
            'a' => 1,
            'b' => ['nested' => 'value'],
            'c' => 3,
        ]);

        self::assertCount(3, $config);
    }

    public function testIteratorYieldsTopLevelElements(): void
    {
        $config = new Config(['a' => 1, 'b' => 2]);

        $result = iterator_to_array($config);

        self::assertSame(['a' => 1, 'b' => 2], $result);
    }

    // =========================================================================
    // ENVIRONMENT
    // =========================================================================

    public function testEnvironmentIsAccessible(): void
    {
        $env = new Environment(['APP_ENV' => 'production']);
        $config = new Config([], $env);

        self::assertSame('production', $config->environment->string('APP_ENV'));
    }

    public function testEnvironmentCanBeNull(): void
    {
        $config = new Config([]);

        self::assertNull($config->environment);
    }
}
