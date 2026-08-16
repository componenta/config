<?php

declare(strict_types=1);

namespace Componenta\Config\Tests;

use Componenta\Config\Loader\EnvLoader;
use PHPUnit\Framework\TestCase;

use function Componenta\Config\env;

final class EnvLoaderSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/componenta_env_' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (['APP_ENV', 'FILE_ONLY', 'LOCAL_VALUE', 'IGNORED_SAMPLE', 'PROCESS_ONLY', 'NATIVE_ONLY'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]); putenv($key);
        }
        foreach (glob($this->root . '/.*') ?: [] as $file) {
            if (!in_array(basename($file), ['.', '..'], true) && is_file($file)) { unlink($file); }
        }
        @rmdir($this->root);
    }

    public function testExplicitFileListExcludesSamplesAndKeepsProcessPrecedence(): void
    {
        file_put_contents($this->root . '/.env', "APP_ENV=development\nFILE_ONLY=base\nLOCAL_VALUE=base\n");
        file_put_contents($this->root . '/.env.local', "LOCAL_VALUE=local\n");
        file_put_contents($this->root . '/.env.example', "IGNORED_SAMPLE=example\nAPP_ENV=test\n");
        $_ENV['APP_ENV'] = 'production'; $_ENV['PROCESS_ONLY'] = 'process';
        $environment = (new EnvLoader($this->root, filenames: ['.env', '.env.local']))->load(override: false);
        self::assertNotNull($environment);
        self::assertSame('production', $environment->string('APP_ENV'));
        self::assertSame('base', $environment->string('FILE_ONLY'));
        self::assertSame('local', $environment->string('LOCAL_VALUE'));
        self::assertSame('process', $environment->string('PROCESS_ONLY'));
        self::assertFalse($environment->has('IGNORED_SAMPLE'));
    }

    public function testReadIsPureAndNativeProcessValuesAreVisibleEverywhere(): void
    {
        file_put_contents($this->root . '/.env', "FILE_ONLY=file\n"); putenv('NATIVE_ONLY=native');
        $loader = new EnvLoader($this->root, filenames: ['.env']);
        self::assertSame(['FILE_ONLY' => 'file'], $loader->read());
        self::assertArrayNotHasKey('FILE_ONLY', $_ENV);
        self::assertSame('native', env('NATIVE_ONLY'));
        $environment = $loader->load();
        self::assertNotNull($environment);
        self::assertSame('native', $environment->string('NATIVE_ONLY'));
        self::assertSame('file', $environment->string('FILE_ONLY'));
    }

    public function testRequiredValuesMayComeFromTheDeploymentEnvironment(): void
    {
        $_ENV['PROCESS_ONLY'] = 'present';
        $environment = (new EnvLoader($this->root, required: ['PROCESS_ONLY'], filenames: ['.env']))->load();
        self::assertNotNull($environment);
        self::assertSame('present', $environment->string('PROCESS_ONLY'));
    }
}
