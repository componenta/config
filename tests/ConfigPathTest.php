<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\ConfigPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigPathTest extends TestCase
{
    public function testSegmentsReturnsArrayOfParts(): void
    {
        $path = new ConfigPath('database.connections.mysql');

        self::assertSame(['database', 'connections', 'mysql'], $path->toArray());
    }

    public function testSegmentsReturnsSingleElementForSimplePath(): void
    {
        $path = new ConfigPath('database');

        self::assertSame(['database'], $path->toArray());
    }

    public function testFirstReturnsFirstSegment(): void
    {
        $path = new ConfigPath('database.connections.mysql');

        self::assertSame('database', $path->first());
    }

    public function testLastReturnsLastSegment(): void
    {
        $path = new ConfigPath('database.connections.mysql');

        self::assertSame('mysql', $path->last());
    }

    public function testFirstAndLastAreSameForSimplePath(): void
    {
        $path = new ConfigPath('database');

        self::assertSame('database', $path->first());
        self::assertSame('database', $path->last());
    }

    public function testIsNestedReturnsTrueForDottedPath(): void
    {
        $path = new ConfigPath('database.host');

        self::assertTrue($path->isNested());
    }

    public function testIsNestedReturnsFalseForSimplePath(): void
    {
        $path = new ConfigPath('database');

        self::assertFalse($path->isNested());
    }

    public function testToStringReturnsOriginalValue(): void
    {
        $path = new ConfigPath('database.connections.mysql');

        self::assertSame('database.connections.mysql', (string) $path);
    }

    #[DataProvider('pathProvider')]
    public function testPathBehavior(string $input, array $expectedSegments, bool $expectedNested): void
    {
        $path = new ConfigPath($input);

        self::assertSame($expectedSegments, $path->toArray());
        self::assertSame($expectedNested, $path->isNested());
        self::assertSame($input, $path->value);
    }

    public static function pathProvider(): iterable
    {
        yield 'simple' => ['key', ['key'], false];
        yield 'two levels' => ['a.b', ['a', 'b'], true];
        yield 'three levels' => ['a.b.c', ['a', 'b', 'c'], true];
        yield 'empty string' => ['', [''], false];
    }
}
