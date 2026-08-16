<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Loader;

use Componenta\Config\Exception\EnvLoaderException;
use Componenta\Config\Environment;
use Componenta\Config\Loader\EnvLoader;
use PHPUnit\Framework\TestCase;

final class EnvLoaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/env';

    protected function setUp(): void
    {
        foreach (array_keys($_ENV) as $key) {
            if (str_starts_with($key, 'TEST_') || str_starts_with($key, 'APP_') || str_starts_with($key, 'KEY')) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    // =========================================================================
    // BASIC LOADING
    // =========================================================================

    public function testLoadParsesSimpleEnvFile(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/simple');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('MyApp', $environment->string('APP_NAME'));
        self::assertSame('production', $environment->string('APP_ENV'));
        self::assertSame('true', $environment->string('DEBUG'));
    }

    public function testLoadPopulatesEnvSuperglobal(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/simple');
        $loader->load();

        self::assertSame('MyApp', $_ENV['APP_NAME']);
    }

    public function testLoadPopulatesServerSuperglobal(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/simple');
        $loader->load();

        self::assertSame('MyApp', $_SERVER['APP_NAME']);
    }

    public function testLoadSkipsServerWhenDisabled(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/simple');
        $loader->load(populateServer: false);

        self::assertSame('MyApp', $_ENV['APP_NAME']);
        self::assertArrayNotHasKey('APP_NAME', $_SERVER);
    }

    // =========================================================================
    // QUOTED VALUES
    // =========================================================================

    public function testLoadHandlesDoubleQuotedValues(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/quoted');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('Hello World', $environment->string('MESSAGE_DOUBLE'));
    }

    public function testLoadHandlesSingleQuotedValues(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/quoted');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('Hello World', $environment->string('MESSAGE_SINGLE'));
    }

    public function testLoadHandlesEscapeSequencesInDoubleQuotes(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/quoted');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame("line1\nline2\ttab", $environment->string('ESCAPE_DOUBLE'));
    }

    public function testLoadPreservesLiteralInSingleQuotes(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/quoted');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('no\\nescape', $environment->string('ESCAPE_SINGLE'));
    }

    public function testLoadHandlesEscapedQuotesInDoubleQuotes(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/quoted');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('He said "hello"', $environment->string('ESCAPED_QUOTES'));
    }

    // =========================================================================
    // COMMENTS
    // =========================================================================

    public function testLoadIgnoresComments(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/comments');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertFalse($environment->has('#'));
        self::assertSame('value', $environment->string('KEY'));
        self::assertSame('value2', $environment->string('KEY2'));
    }

    // =========================================================================
    // MULTIPLE FILES
    // =========================================================================

    public function testLoadMergesMultipleEnvFiles(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/override');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('base', $environment->string('BASE'));
        self::assertSame('local', $environment->string('LOCAL'));
    }

    public function testLoadLocalOverridesBase(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/override');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('local', $environment->string('KEY'));
    }

    // =========================================================================
    // REQUIRED VARIABLES
    // =========================================================================

    public function testLoadValidatesRequiredVariables(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/required', required: ['APP_KEY']);
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('secret', $environment->string('APP_KEY'));
    }

    public function testLoadThrowsWhenRequiredVariableMissing(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/required', required: ['MISSING_KEY']);

        $this->expectException(EnvLoaderException::class);
        $this->expectExceptionMessage('Required environment variables are missing: MISSING_KEY');

        $loader->load();
    }

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    public function testReadReturnsNullWhenNoFilesFound(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/empty');

        self::assertNull($loader->read());
    }

    public function testLoadThrowsForInvalidVariableName(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/invalid-name');

        $this->expectException(EnvLoaderException::class);
        $this->expectExceptionMessage('Invalid .env format');

        $loader->load();
    }

    public function testLoadThrowsForMissingEqualsSign(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/invalid-format');

        $this->expectException(EnvLoaderException::class);
        $this->expectExceptionMessage('Invalid .env format');

        $loader->load();
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function testLoadHandlesEmptyValue(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/edge-cases');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('', $environment->string('EMPTY'));
    }

    public function testLoadHandlesSpacesInValue(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/edge-cases');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('value with spaces', $environment->string('KEY_SPACES'));
    }

    public function testLoadHandlesEqualsInValue(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/edge-cases');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('value=with=equals', $environment->string('KEY_EQUALS'));
    }

    public function testLoadHandlesUnderscoreStart(): void
    {
        $loader = new EnvLoader(self::FIXTURES . '/edge-cases');
        $environment = $loader->load();

        self::assertInstanceOf(Environment::class, $environment);
        self::assertSame('value', $environment->string('_UNDERSCORE'));
    }
}
