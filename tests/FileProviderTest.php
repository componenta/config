<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\FileProvider;
use PHPUnit\Framework\TestCase;

final class FileProviderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures/config';

    // =========================================================================
    // PHP FILES
    // =========================================================================

    public function testInvokeLoadsPhpFile(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/app.php');

        $config = $provider();

        self::assertSame('Componenta', $config['app']['name']);
        self::assertTrue($config['debug']);
    }

    public function testInvokeMergesRecursively(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/merge/*.php');

        $config = $provider();

        self::assertSame('Base', $config['app']['name']);
        self::assertTrue($config['app']['debug']);
    }

    public function testInvokeIgnoresNonArrayReturns(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/{app,invalid}.php');

        $config = $provider();

        self::assertSame('Componenta', $config['app']['name']);
        self::assertArrayNotHasKey(0, $config);
    }

    // =========================================================================
    // JSON FILES
    // =========================================================================

    public function testInvokeLoadsJsonFile(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/database.json');

        $config = $provider();

        self::assertSame('localhost', $config['database']['host']);
        self::assertSame(3306, $config['database']['port']);
    }

    public function testInvokeMergesPhpAndJsonFiles(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/*.{php,json}');

        $config = $provider();

        self::assertArrayHasKey('app', $config);
        self::assertArrayHasKey('database', $config);
    }

    public function testInvokeUsesConfigMergeSemanticsForNumericKeys(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/lists/*.php');

        $config = $provider();

        self::assertSame(['auth', 'csrf'], $config['middlewares']);
    }

    // =========================================================================
    // PATTERN MATCHING
    // =========================================================================

    public function testInvokeProcessesFilesInOrder(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/order/*.php');

        $config = $provider();

        self::assertFalse($config['first']);
        self::assertTrue($config['second']);
        self::assertSame('second', $config['order']);
    }

    public function testInvokeReturnsEmptyArrayWhenNoFilesMatch(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/nonexistent/*.php');

        $config = $provider();

        self::assertSame([], $config);
    }

    public function testInvokeHandlesEmptyDirectory(): void
    {
        $provider = new FileProvider(self::FIXTURES . '/empty/*.php');

        $config = $provider();

        self::assertSame([], $config);
    }
}
