<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigPath;
use PHPUnit\Framework\TestCase;

use function Componenta\Config\env;
use function Componenta\Config\env_array;
use function Componenta\Config\env_bool;
use function Componenta\Config\env_float;
use function Componenta\Config\env_int;
use function Componenta\Config\env_string;
use function Componenta\Config\config_merge;
use function Componenta\Config\path;

final class FunctionsTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
    }

    // =========================================================================
    // path()
    // =========================================================================

    public function testPathReturnsPathInstance(): void
    {
        $path = path('database.host');

        self::assertInstanceOf(ConfigPath::class, $path);
        self::assertSame('database.host', (string) $path);
    }

    // =========================================================================
    // env() - Basic
    // =========================================================================

    public function testEnvReturnsValueWhenExists(): void
    {
        $_ENV['TEST_KEY'] = 'test-value';

        self::assertSame('test-value', env('TEST_KEY'));
    }

    public function testEnvThrowsWhenKeyMissingAndNoDefault(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Configuration key 'MISSING_KEY' is missing");

        env('MISSING_KEY');
    }

    public function testEnvReturnsDefaultWhenKeyMissing(): void
    {
        self::assertSame('default', env('MISSING_KEY', 'default'));
    }

    // =========================================================================
    // env() - Type Conversion
    // =========================================================================

    public function testEnvConvertsNumericStringToInt(): void
    {
        $_ENV['PORT'] = '3306';

        self::assertSame(3306, env('PORT'));
    }

    public function testEnvConvertsNumericStringToFloat(): void
    {
        $_ENV['RATE'] = '3.14';

        self::assertSame(3.14, env('RATE'));
    }

    public function testEnvConvertsTruthyStringsToTrue(): void
    {
        foreach (['true', 'yes', 'y', 'on', 'enabled'] as $value) {
            $_ENV['FLAG'] = $value;
            self::assertTrue(env('FLAG'), "Failed for value: $value");
        }
    }

    public function testEnvConvertsFalsyStringsToFalse(): void
    {
        foreach (['false', 'no', 'n', 'off', 'disabled'] as $value) {
            $_ENV['FLAG'] = $value;
            self::assertFalse(env('FLAG'), "Failed for value: $value");
        }
    }

    public function testEnvConvertsNumericStrings(): void
    {
        $_ENV['ONE'] = '1';
        $_ENV['ZERO'] = '0';

        self::assertSame(1, env('ONE'));
        self::assertSame(0, env('ZERO'));
    }

    // =========================================================================
    // env_string()
    // =========================================================================

    public function testEnvStringReturnsString(): void
    {
        $_ENV['NAME'] = 'MyApp';

        self::assertSame('MyApp', env_string('NAME'));
    }

    public function testEnvStringThrowsWhenKeyMissingAndNoDefault(): void
    {
        $this->expectException(ConfigException::class);

        env_string('MISSING_KEY');
    }

    public function testEnvStringReturnsDefaultWhenKeyMissing(): void
    {
        self::assertSame('default', env_string('MISSING_KEY', 'default'));
    }

    public function testEnvStringConvertsNumericToString(): void
    {
        $_ENV['PORT'] = '3306';

        self::assertSame('3306', env_string('PORT'));
    }

    // =========================================================================
    // env_int()
    // =========================================================================

    public function testEnvIntReturnsInt(): void
    {
        $_ENV['PORT'] = '3306';

        self::assertSame(3306, env_int('PORT'));
    }

    public function testEnvIntThrowsWhenKeyMissingAndNoDefault(): void
    {
        $this->expectException(ConfigException::class);

        env_int('MISSING_KEY');
    }

    public function testEnvIntReturnsDefaultWhenKeyMissing(): void
    {
        self::assertSame(8080, env_int('MISSING_KEY', 8080));
    }

    public function testEnvIntThrowsWhenCannotConvert(): void
    {
        $_ENV['VALUE'] = 'not-a-number';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Cannot convert environment variable 'VALUE' to int");

        env_int('VALUE');
    }

    // =========================================================================
    // env_float()
    // =========================================================================

    public function testEnvFloatReturnsFloat(): void
    {
        $_ENV['RATE'] = '3.14';

        self::assertSame(3.14, env_float('RATE'));
    }

    public function testEnvFloatThrowsWhenKeyMissingAndNoDefault(): void
    {
        $this->expectException(ConfigException::class);

        env_float('MISSING_KEY');
    }

    public function testEnvFloatReturnsDefaultWhenKeyMissing(): void
    {
        self::assertSame(1.5, env_float('MISSING_KEY', 1.5));
    }

    public function testEnvFloatThrowsWhenCannotConvert(): void
    {
        $_ENV['VALUE'] = 'not-a-number';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Cannot convert environment variable 'VALUE' to float");

        env_float('VALUE');
    }

    // =========================================================================
    // env_bool()
    // =========================================================================

    public function testEnvBoolReturnsTrue(): void
    {
        $_ENV['DEBUG'] = 'true';

        self::assertTrue(env_bool('DEBUG'));
    }

    public function testEnvBoolReturnsFalse(): void
    {
        $_ENV['DEBUG'] = 'false';

        self::assertFalse(env_bool('DEBUG'));
    }

    public function testEnvBoolThrowsWhenKeyMissingAndNoDefault(): void
    {
        $this->expectException(ConfigException::class);

        env_bool('MISSING_KEY');
    }

    public function testEnvBoolReturnsDefaultWhenKeyMissing(): void
    {
        self::assertTrue(env_bool('MISSING_KEY', true));
        self::assertFalse(env_bool('MISSING_KEY', false));
    }

    // =========================================================================
    // env_array()
    // =========================================================================

    public function testEnvArrayReturnsParsedArray(): void
    {
        $_ENV['DRIVERS'] = 'redis, memcached, file';

        self::assertSame(['redis', 'memcached', 'file'], env_array('DRIVERS'));
    }

    public function testEnvArrayThrowsWhenKeyMissingAndNoDefault(): void
    {
        $this->expectException(ConfigException::class);

        env_array('MISSING_KEY');
    }

    public function testEnvArrayReturnsDefaultWhenKeyMissing(): void
    {
        self::assertSame(['default'], env_array('MISSING_KEY', ['default']));
    }

    public function testEnvArrayReturnsEmptyArrayForEmptyString(): void
    {
        $_ENV['EMPTY'] = '';

        self::assertSame([], env_array('EMPTY'));
    }

    public function testConfigMergeAppendsNumericKeysAndMergesStringKeys(): void
    {
        $merged = config_merge(
            ['middlewares' => ['auth'], 'database' => ['host' => 'localhost']],
            ['middlewares' => ['csrf'], 'database' => ['port' => 3306]],
        );

        self::assertSame(['auth', 'csrf'], $merged['middlewares']);
        self::assertSame(['host' => 'localhost', 'port' => 3306], $merged['database']);
    }

    public function testConfigMergeCanReplaceNumericIndexes(): void
    {
        $merged = config_merge(
            ['middlewares' => ['auth', 'csrf']],
            [
                ConfigKey::OVERRIDE_INDEXES => true,
                'middlewares' => [1 => 'rate-limit'],
            ],
        );

        self::assertSame(['auth', 'rate-limit'], $merged['middlewares']);
        self::assertArrayNotHasKey(ConfigKey::OVERRIDE_INDEXES, $merged);
    }
}
