<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\Exception\InvalidConfigValueException;
use Stringable;

function configValue(mixed $value): Config
{
    return new Config(['value' => $value], new Environment([]));
}

it('converts supported boolean representations through Config', function (
    mixed $input,
    bool $expected,
): void {
    expect(configValue($input)->bool('value'))->toBe($expected);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'integer one' => [1, true],
    'integer zero' => [0, false],
    'true string' => ['true', true],
    'trimmed yes' => [' YES ', true],
    'enabled string' => ['enabled', true],
    'false string' => ['false', false],
    'uppercase off' => ['OFF', false],
    'empty string' => ['', false],
]);

it('rejects ambiguous boolean representations through Config', function (mixed $value): void {
    expect(fn() => configValue($value)->bool('value'))
        ->toThrow(InvalidConfigValueException::class);
})->with([
    'unknown string' => ['maybe'],
    'integer other than zero or one' => [2],
    'array' => [[]],
]);

it('converts only lossless integer values through Config', function (
    mixed $input,
    int $expected,
): void {
    expect(configValue($input)->int('value'))->toBe($expected);
})->with([
    'integer' => [42, 42],
    'integer string' => ['42', 42],
    'leading zeroes' => ['001', 1],
    'explicit plus' => ['+001', 1],
    'negative leading zeroes' => ['-001', -1],
    'integral float' => [3.0, 3],
    'true' => [true, 1],
    'false' => [false, 0],
]);

it('rejects lossy and invalid integer values through Config', function (mixed $value): void {
    expect(fn() => configValue($value)->int('value'))
        ->toThrow(InvalidConfigValueException::class);
})->with([
    'fractional float' => [3.9],
    'decimal string' => ['3.9'],
    'scientific notation' => ['1e3'],
    'overflow' => [PHP_INT_MAX . '0'],
    'infinity' => [INF],
    'not a number' => [NAN],
    'invalid string' => ['not-a-number'],
    'empty string' => [''],
]);

it('converts finite floating point values through Config', function (
    mixed $input,
    float $expected,
): void {
    expect(configValue($input)->float('value'))->toBe($expected);
})->with([
    'integer' => [42, 42.0],
    'decimal string' => ['3.14', 3.14],
    'scientific notation' => ['1e3', 1000.0],
    'false' => [false, 0.0],
]);

it('rejects invalid and non-finite floating point values through Config', function (
    mixed $value,
): void {
    expect(fn() => configValue($value)->float('value'))
        ->toThrow(InvalidConfigValueException::class);
})->with([
    'invalid string' => ['invalid'],
    'infinity' => [INF],
    'overflow' => ['1e9999'],
]);

it('converts scalar and Stringable values to strings through Config', function (): void {
    $stringable = new class () implements Stringable {
        public function __toString(): string
        {
            return 'value';
        }
    };

    expect(configValue('string')->string('value'))->toBe('string')
        ->and(configValue(42)->string('value'))->toBe('42')
        ->and(configValue($stringable)->string('value'))->toBe('value')
        ->and(fn() => configValue([])->string('value'))
        ->toThrow(InvalidConfigValueException::class);
});

it('converts arrays and comma-separated values through Config', function (): void {
    expect(configValue(['a'])->array('value'))->toBe(['a'])
        ->and(configValue('a, b')->array('value'))->toBe(['a', 'b'])
        ->and(configValue('')->array('value'))->toBe([])
        ->and(configValue(null)->array('value'))->toBe([])
        ->and(configValue(1)->array('value'))->toBe([1]);
});
