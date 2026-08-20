<?php

declare(strict_types=1);

use Componenta\Config\Internal\TypeConverter;

it('converts supported boolean representations', function (mixed $input, bool $expected): void {
    expect(TypeConverter::toBool($input))->toBe($expected);
})->with([
    [true, true], [false, false], [1, true], [0, false],
    ['true', true], [' YES ', true], ['enabled', true],
    ['false', false], ['OFF', false], ['', false],
]);

it('rejects ambiguous boolean values', function (): void {
    expect(TypeConverter::toBool('maybe'))->toBeNull()
        ->and(TypeConverter::toBool(2))->toBeNull()
        ->and(TypeConverter::toBool([]))->toBeNull();
});

it('converts integer values according to the public config contract', function (): void {
    expect(TypeConverter::toInt(42))->toBe(42)
        ->and(TypeConverter::toInt('42'))->toBe(42)
        ->and(TypeConverter::toInt(true))->toBe(1)
        ->and(TypeConverter::toInt(false))->toBe(0)
        ->and(TypeConverter::toInt('not-a-number'))->toBeNull()
        ->and(TypeConverter::toInt(''))->toBeNull();
});

it('converts floating point values', function (): void {
    expect(TypeConverter::toFloat(42))->toBe(42.0)
        ->and(TypeConverter::toFloat('3.14'))->toBe(3.14)
        ->and(TypeConverter::toFloat(false))->toBe(0.0)
        ->and(TypeConverter::toFloat('invalid'))->toBeNull();
});

it('converts scalar and Stringable values to string', function (): void {
    $stringable = new class implements Stringable {
        public function __toString(): string { return 'value'; }
    };

    expect(TypeConverter::toString('string'))->toBe('string')
        ->and(TypeConverter::toString(42))->toBe('42')
        ->and(TypeConverter::toString($stringable))->toBe('value')
        ->and(TypeConverter::toString([]))->toBeNull();
});

it('converts arrays and comma-separated strings', function (): void {
    expect(TypeConverter::toArray(['a']))->toBe(['a'])
        ->and(TypeConverter::toArray('a, b'))->toBe(['a', 'b'])
        ->and(TypeConverter::toArray(''))->toBe([])
        ->and(TypeConverter::toArray(null))->toBe([])
        ->and(TypeConverter::toArray(1))->toBe([1]);
});
