<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Exception;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Exception\ConfigExceptionInterface;
use Componenta\Config\Exception\EnvLoaderException;
use Componenta\Config\Exception\InvalidConfigValueException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    // =========================================================================
    // ConfigException
    // =========================================================================

    public function testConfigExceptionMissingKeyContainsKeyInMessage(): void
    {
        $exception = ConfigException::missingKey('database.host');

        self::assertSame("Configuration key 'database.host' is missing", $exception->getMessage());
        self::assertSame('database.host', $exception->key);
    }

    public function testConfigExceptionNullValueContainsKeyInMessage(): void
    {
        $exception = ConfigException::nullValue('app.secret');

        self::assertSame("Configuration key 'app.secret' is null", $exception->getMessage());
        self::assertSame('app.secret', $exception->key);
    }

    public function testConfigExceptionImplementsInterface(): void
    {
        $exception = new ConfigException('test');

        self::assertInstanceOf(ConfigExceptionInterface::class, $exception);
        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    // =========================================================================
    // InvalidConfigValueException
    // =========================================================================

    public function testInvalidConfigValueExceptionCannotConvertContainsDetails(): void
    {
        $exception = InvalidConfigValueException::cannotConvert('port', 'int', ['array']);

        self::assertStringContainsString('port', $exception->getMessage());
        self::assertStringContainsString('int', $exception->getMessage());
        self::assertStringContainsString('array', $exception->getMessage());
        self::assertSame('port', $exception->key);
        self::assertSame('int', $exception->expectedType);
        self::assertSame('array', $exception->actualType);
    }

    public function testInvalidConfigValueExceptionWithObjectShowsClassName(): void
    {
        $exception = InvalidConfigValueException::cannotConvert('value', 'string', new \stdClass());

        self::assertStringContainsString('stdClass', $exception->getMessage());
        self::assertSame('stdClass', $exception->actualType);
    }

    public function testInvalidConfigValueExceptionImplementsInterface(): void
    {
        $exception = new InvalidConfigValueException('test');

        self::assertInstanceOf(ConfigExceptionInterface::class, $exception);
        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
    }

    // =========================================================================
    // EnvLoaderException
    // =========================================================================

    public function testEnvLoaderExceptionFilesNotFoundContainsPaths(): void
    {
        $exception = EnvLoaderException::filesNotFound(
            ['/path/one', '/path/two'],
            ['.env', '.env.local'],
        );

        self::assertStringContainsString('/path/one', $exception->getMessage());
        self::assertStringContainsString('/path/two', $exception->getMessage());
        self::assertStringContainsString('.env', $exception->getMessage());
        self::assertStringContainsString('.env.local', $exception->getMessage());
    }

    public function testEnvLoaderExceptionRequiredVariablesMissingContainsNames(): void
    {
        $exception = EnvLoaderException::requiredVariablesMissing(['APP_KEY', 'DB_HOST']);

        self::assertStringContainsString('APP_KEY', $exception->getMessage());
        self::assertStringContainsString('DB_HOST', $exception->getMessage());
    }

    public function testEnvLoaderExceptionParseErrorContainsDetails(): void
    {
        $exception = EnvLoaderException::parseError('/path/.env', 5, 'INVALID LINE');

        self::assertStringContainsString('line 5', $exception->getMessage());
        self::assertStringContainsString('/path/.env', $exception->getMessage());
        self::assertStringContainsString('INVALID LINE', $exception->getMessage());
    }

    public function testEnvLoaderExceptionFileNotReadableContainsPath(): void
    {
        $exception = EnvLoaderException::fileNotReadable('/path/.env');

        self::assertStringContainsString('/path/.env', $exception->getMessage());
    }

    public function testEnvLoaderExceptionImplementsInterface(): void
    {
        $exception = new EnvLoaderException('test');

        self::assertInstanceOf(ConfigExceptionInterface::class, $exception);
        self::assertInstanceOf(\RuntimeException::class, $exception);
    }
}
