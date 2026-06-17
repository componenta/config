<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Internal;

use Componenta\Config\Internal\TypeConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypeConverterTest extends TestCase
{
    // =========================================================================
    // toBool
    // =========================================================================

    #[DataProvider('boolValuesProvider')]
    public function testToBoolConvertsValues(mixed $input, bool $expected): void
    {
        self::assertSame($expected, TypeConverter::toBool($input));
    }

    public static function boolValuesProvider(): iterable
    {
        // Native booleans
        yield 'true' => [true, true];
        yield 'false' => [false, false];

        // Truthy strings
        yield 'string true' => ['true', true];
        yield 'string TRUE' => ['TRUE', true];
        yield 'string True' => ['True', true];
        yield 'string 1' => ['1', true];
        yield 'string yes' => ['yes', true];
        yield 'string YES' => ['YES', true];
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

        // Strings with whitespace
        yield 'string true with spaces' => ['  true  ', true];
        yield 'string false with spaces' => ['  false  ', false];

        // Numeric boolean literals
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
    }

    #[DataProvider('ambiguousBoolValuesProvider')]
    public function testToBoolReturnsNullForAmbiguousValues(mixed $input): void
    {
        self::assertNull(TypeConverter::toBool($input));
    }

    public static function ambiguousBoolValuesProvider(): iterable
    {
        yield 'int positive' => [42];
        yield 'int negative' => [-1];
        yield 'null' => [null];
        yield 'empty array' => [[]];
        yield 'non-empty array' => [[1]];
        yield 'unknown string' => ['truthy'];
    }

    // =========================================================================
    // toInt
    // =========================================================================

    #[DataProvider('intValuesProvider')]
    public function testToIntConvertsValues(mixed $input, ?int $expected): void
    {
        self::assertSame($expected, TypeConverter::toInt($input));
    }

    public static function intValuesProvider(): iterable
    {
        // Native integers
        yield 'int positive' => [42, 42];
        yield 'int zero' => [0, 0];
        yield 'int negative' => [-42, -42];

        // Floats (truncated)
        yield 'float positive' => [3.14, 3];
        yield 'float negative' => [-3.14, -3];

        // Numeric strings
        yield 'string int' => ['42', 42];
        yield 'string negative' => ['-42', -42];
        yield 'string zero' => ['0', 0];
        yield 'string with spaces' => ['  42  ', 42];

        // Booleans
        yield 'true' => [true, 1];
        yield 'false' => [false, 0];

        // Non-convertible
        yield 'empty string' => ['', null];
        yield 'non-numeric string' => ['abc', null];
        yield 'array' => [[1, 2], null];
        yield 'null' => [null, null];
    }

    // =========================================================================
    // toFloat
    // =========================================================================

    #[DataProvider('floatValuesProvider')]
    public function testToFloatConvertsValues(mixed $input, ?float $expected): void
    {
        self::assertSame($expected, TypeConverter::toFloat($input));
    }

    public static function floatValuesProvider(): iterable
    {
        // Native floats
        yield 'float positive' => [3.14, 3.14];
        yield 'float negative' => [-3.14, -3.14];
        yield 'float zero' => [0.0, 0.0];

        // Integers
        yield 'int positive' => [42, 42.0];
        yield 'int zero' => [0, 0.0];

        // Numeric strings
        yield 'string float' => ['3.14', 3.14];
        yield 'string int' => ['42', 42.0];
        yield 'string with spaces' => ['  3.14  ', 3.14];

        // Booleans
        yield 'true' => [true, 1.0];
        yield 'false' => [false, 0.0];

        // Non-convertible
        yield 'empty string' => ['', null];
        yield 'non-numeric string' => ['abc', null];
        yield 'array' => [[1.0], null];
        yield 'null' => [null, null];
    }

    // =========================================================================
    // toString
    // =========================================================================

    #[DataProvider('stringValuesProvider')]
    public function testToStringConvertsValues(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, TypeConverter::toString($input));
    }

    public static function stringValuesProvider(): iterable
    {
        // Native strings
        yield 'string' => ['hello', 'hello'];
        yield 'empty string' => ['', ''];

        // Scalars
        yield 'int' => [42, '42'];
        yield 'float' => [3.14, '3.14'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, ''];

        // Non-convertible
        yield 'array' => [['a'], null];
        yield 'null' => [null, null];
    }

    public function testToStringConvertsStringable(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        self::assertSame('stringable-value', TypeConverter::toString($stringable));
    }

    // =========================================================================
    // toArray
    // =========================================================================

    #[DataProvider('arrayValuesProvider')]
    public function testToArrayConvertsValues(mixed $input, array $expected): void
    {
        self::assertSame($expected, TypeConverter::toArray($input));
    }

    public static function arrayValuesProvider(): iterable
    {
        // Native arrays
        yield 'array' => [['a', 'b'], ['a', 'b']];
        yield 'empty array' => [[], []];
        yield 'associative array' => [['key' => 'value'], ['key' => 'value']];

        // Comma-separated strings
        yield 'csv string' => ['a,b,c', ['a', 'b', 'c']];
        yield 'csv with spaces' => ['a, b, c', ['a', 'b', 'c']];
        yield 'single value string' => ['single', ['single']];
        yield 'empty string' => ['', []];

        // Other types wrapped
        yield 'int' => [42, [42]];
        yield 'float' => [3.14, [3.14]];
        yield 'bool' => [true, [true]];

        // Null
        yield 'null' => [null, []];
    }

    // =========================================================================
    // Convertibility Checks
    // =========================================================================

    public function testIsConvertibleToIntReturnsTrueForConvertible(): void
    {
        self::assertTrue(TypeConverter::isConvertibleToInt(42));
        self::assertTrue(TypeConverter::isConvertibleToInt('42'));
        self::assertTrue(TypeConverter::isConvertibleToInt(3.14));
    }

    public function testIsConvertibleToIntReturnsFalseForNonConvertible(): void
    {
        self::assertFalse(TypeConverter::isConvertibleToInt('abc'));
        self::assertFalse(TypeConverter::isConvertibleToInt([]));
        self::assertFalse(TypeConverter::isConvertibleToInt(null));
    }

    public function testIsConvertibleToFloatReturnsTrueForConvertible(): void
    {
        self::assertTrue(TypeConverter::isConvertibleToFloat(3.14));
        self::assertTrue(TypeConverter::isConvertibleToFloat('3.14'));
        self::assertTrue(TypeConverter::isConvertibleToFloat(42));
    }

    public function testIsConvertibleToFloatReturnsFalseForNonConvertible(): void
    {
        self::assertFalse(TypeConverter::isConvertibleToFloat('abc'));
        self::assertFalse(TypeConverter::isConvertibleToFloat([]));
    }

    public function testIsConvertibleToStringReturnsTrueForConvertible(): void
    {
        self::assertTrue(TypeConverter::isConvertibleToString('hello'));
        self::assertTrue(TypeConverter::isConvertibleToString(42));
        self::assertTrue(TypeConverter::isConvertibleToString(3.14));
    }

    public function testIsConvertibleToStringReturnsFalseForNonConvertible(): void
    {
        self::assertFalse(TypeConverter::isConvertibleToString([]));
        self::assertFalse(TypeConverter::isConvertibleToString(null));
    }
}
