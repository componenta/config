<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\Config;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigException;
use Componenta\Config\ConfigPath;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/fixtures';
    private const string RUNTIME = __DIR__ . '/fixtures/runtime';

    protected function tearDown(): void
    {
        $this->cleanRuntime();
    }

    private function cleanRuntime(): void
    {
        $files = glob(self::RUNTIME . '/*');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (basename($file) === '.gitkeep') {
                continue;
            }

            if (is_dir($file)) {
                $this->removeDirectory($file);
            } else {
                unlink($file);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    public function testLoadAggregatesProviders(): void
    {
        $config = ConfigLoader::load(
            null,
            static fn(): array => ['a' => 1, 'b' => 2],
            static fn(): array => ['c' => 3],
        );

        self::assertSame(1, $config->get('a'));
        self::assertSame(2, $config->get('b'));
        self::assertSame(3, $config->get('c'));
    }

    public function testLoadMergesProvidersRecursively(): void
    {
        $config = ConfigLoader::load(
            null,
            static fn(): array => ['database' => ['host' => 'localhost']],
            static fn(): array => ['database' => ['port' => 3306]],
        );

        self::assertSame('localhost', $config->get(new ConfigPath('database.host')));
        self::assertSame(3306, $config->get(new ConfigPath('database.port')));
    }

    public function testLoadReturnsEmptyConfigWithNoProviders(): void
    {
        $config = ConfigLoader::load(null);

        self::assertSame([], $config->toArray());
    }

    public function testLoadThrowsWhenProviderReturnsNonArray(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Configuration provider must return an array or iterable');

        ConfigLoader::load(null, static fn(): string => 'not-an-array');
    }

    public function testLoadAcceptsIterableProviders(): void
    {
        $config = ConfigLoader::load(
            null,
            static fn(): \ArrayIterator => new \ArrayIterator(['a' => 1]),
        );

        self::assertSame(1, $config->get('a'));
    }

    public function testLoadKeepsEnvironmentContract(): void
    {
        $environment = new Environment(['APP_ENV' => 'testing']);

        $config = ConfigLoader::load($environment, static fn(): array => ['app' => 'test']);

        self::assertSame($environment, $config->environment);
        self::assertSame('testing', $config->environment->string('APP_ENV'));
    }

    public function testLoadWithoutEnvironmentHasNullEnvironment(): void
    {
        $config = ConfigLoader::load(null);

        self::assertNull($config->environment);
    }

    public function testLoadFromFileReadsCachedConfig(): void
    {
        $config = ConfigLoader::loadFromFile(self::FIXTURES . '/cache/config.cache.php');

        self::assertTrue($config->get('cached'));
    }

    public function testLoadFromFileIncludesEnvironment(): void
    {
        $config = ConfigLoader::loadFromFile(self::FIXTURES . '/cache/config-with-env.cache.php');

        self::assertInstanceOf(Environment::class, $config->environment);
        self::assertSame('cached', $config->environment->string('APP_ENV'));
    }

    public function testLoadFromFileCanPopulateEnvironmentGlobals(): void
    {
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);

        ConfigLoader::loadFromFile(self::FIXTURES . '/cache/config-with-env.cache.php', populateEnv: true);

        self::assertSame('cached', $_ENV['APP_ENV']);
        self::assertSame('cached', $_SERVER['APP_ENV']);
    }

    public function testLoadFromFileThrowsWhenCachePayloadIsInvalid(): void
    {
        $cacheFile = self::RUNTIME . '/invalid.cache.php';

        file_put_contents($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn false;\n");

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Invalid cache file format');

        ConfigLoader::loadFromFile($cacheFile);
    }

    public function testExportWritesCacheFile(): void
    {
        $cacheFile = self::RUNTIME . '/export.cache.php';

        ConfigLoader::export(new Config(['exported' => true]), $cacheFile);

        self::assertFileExists($cacheFile);

        $cached = include $cacheFile;
        self::assertSame(['exported' => true], $cached['config']);
    }

    public function testExportIncludesEnvironment(): void
    {
        $cacheFile = self::RUNTIME . '/export-env.cache.php';
        $environment = new Environment(['APP_ENV' => 'production']);

        ConfigLoader::export(new Config(['app' => 'test'], $environment), $cacheFile);

        $cached = include $cacheFile;
        self::assertSame(['APP_ENV' => 'production'], $cached['environment']);
    }

    public function testExportCreatesDirectory(): void
    {
        $cacheFile = self::RUNTIME . '/nested/deep/config.cache.php';

        ConfigLoader::export(new Config(['test' => true]), $cacheFile);

        self::assertFileExists($cacheFile);
    }
}
