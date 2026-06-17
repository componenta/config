<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\DefaultValue;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\InvalidConfigValueException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    // =========================================================================
    // GET
    // =========================================================================

    public function testGetReturnsValue(): void
    {
        $env = new Environment(['APP_NAME' => 'MyApp']);

        self::assertSame('MyApp', $env->get('APP_NAME'));
    }

    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $env = new Environment([]);

        self::assertSame('default', $env->get('MISSING', 'default'));
    }

    public function testGetThrowsWhenKeyMissingAndNoDefault(): void
    {
        $env = new Environment([]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Configuration key 'MISSING' is missing");

        $env->get('MISSING');
    }

    // =========================================================================
    // HAS
    // =========================================================================

    public function testHasReturnsTrueForExistingKey(): void
    {
        $env = new Environment(['APP_ENV' => 'production']);

        self::assertTrue($env->has('APP_ENV'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $env = new Environment([]);

        self::assertFalse($env->has('MISSING'));
    }

    // =========================================================================
    // TYPED ACCESS
    // =========================================================================

    public function testStringReturnsValue(): void
    {
        $env = new Environment(['APP_NAME' => 'MyApp']);

        self::assertSame('MyApp', $env->string('APP_NAME'));
    }

    public function testStringReturnsDefault(): void
    {
        $env = new Environment([]);

        self::assertSame('default', $env->string('MISSING', 'default'));
    }

    public function testStringThrowsWhenCannotConvert(): void
    {
        $env = new Environment(['VALUE' => ['array']]);

        $this->expectException(InvalidConfigValueException::class);

        $env->string('VALUE');
    }

    public function testIntReturnsValue(): void
    {
        $env = new Environment(['PORT' => '3306']);

        self::assertSame(3306, $env->int('PORT'));
    }

    public function testIntReturnsDefault(): void
    {
        $env = new Environment([]);

        self::assertSame(8080, $env->int('PORT', 8080));
    }

    public function testFloatReturnsValue(): void
    {
        $env = new Environment(['RATE' => '0.5']);

        self::assertSame(0.5, $env->float('RATE'));
    }

    #[DataProvider('boolValuesProvider')]
    public function testBoolConvertsValues(string $input, bool $expected): void
    {
        $env = new Environment(['VALUE' => $input]);

        self::assertSame($expected, $env->bool('VALUE'));
    }

    public static function boolValuesProvider(): iterable
    {
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield '1' => ['1', true];
        yield '0' => ['0', false];
        yield 'yes' => ['yes', true];
        yield 'no' => ['no', false];
    }

    public function testArraySplitsCommaSeparatedValues(): void
    {
        $env = new Environment(['HOSTS' => 'host1, host2, host3']);

        self::assertSame(['host1', 'host2', 'host3'], $env->array('HOSTS'));
    }

    public function testArrayReturnsEmptyForEmptyString(): void
    {
        $env = new Environment(['EMPTY' => '']);

        self::assertSame([], $env->array('EMPTY'));
    }

    // =========================================================================
    // PREFIX FILTERING
    // =========================================================================

    public function testPrefixFiltersVariables(): void
    {
        $env = new Environment([
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
            'APP_NAME' => 'MyApp',
        ]);

        $filtered = $env->prefix('DB_');

        self::assertSame([
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
        ], $filtered);
    }

    public function testPrefixRemovesPrefixFromKeys(): void
    {
        $env = new Environment([
            'DB_HOST' => 'localhost',
            'DB_PORT' => '3306',
        ]);

        $filtered = $env->prefix('DB_', removePrefix: true);

        self::assertSame([
            'HOST' => 'localhost',
            'PORT' => '3306',
        ], $filtered);
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    public function testToArrayReturnsAllData(): void
    {
        $data = ['A' => '1', 'B' => '2'];
        $env = new Environment($data);

        self::assertSame($data, $env->toArray());
    }

    public function testKeysReturnsAllKeys(): void
    {
        $env = new Environment(['A' => '1', 'B' => '2']);

        self::assertSame(['A', 'B'], $env->keys());
    }

    public function testCountReturnsNumberOfVariables(): void
    {
        $env = new Environment(['A' => '1', 'B' => '2', 'C' => '3']);

        self::assertCount(3, $env);
    }

    public function testIsEmptyReturnsTrueForEmptyEnvironment(): void
    {
        $env = new Environment([]);

        self::assertTrue($env->isEmpty());
    }

    public function testIsEmptyReturnsFalseForNonEmptyEnvironment(): void
    {
        $env = new Environment(['A' => '1']);

        self::assertFalse($env->isEmpty());
    }

    public function testIteratorYieldsAllVariables(): void
    {
        $data = ['A' => '1', 'B' => '2'];
        $env = new Environment($data);

        self::assertSame($data, iterator_to_array($env));
    }
}
