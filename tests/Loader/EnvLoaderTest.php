<?php

declare(strict_types=1);

use Componenta\Config\Environment;
use Componenta\Config\Exception\EnvLoaderException;
use Componenta\Config\Loader\EnvLoader;

function envLoaderRuntime(): string
{
    static $root;
    return $root ??= sys_get_temp_dir() . '/componenta_env_loader_' . bin2hex(random_bytes(5));
}

function clearEnvLoaderKeys(): void
{
    foreach (['APP_ENV', 'APP_NAME', 'DEBUG', 'BASE', 'LOCAL', 'KEY', 'MESSAGE', 'ESCAPED', 'FILE_ONLY', 'PROCESS_ONLY', 'IGNORED_SAMPLE', 'INVALID_SERVER'] as $key) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
}

beforeEach(function (): void {
    clearEnvLoaderKeys();
    @mkdir(envLoaderRuntime(), 0700, true);
    foreach (glob(envLoaderRuntime() . '/.*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

afterEach(function (): void {
    clearEnvLoaderKeys();
    foreach (glob(envLoaderRuntime() . '/.*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir(envLoaderRuntime());
});

it('always returns a runtime environment snapshot', function (): void {
    expect((new EnvLoader(envLoaderRuntime()))->load())
        ->toBeInstanceOf(Environment::class);
});

it('loads only .env and .env.local by default', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "APP_ENV=development\nBASE=base\nKEY=base\n");
    file_put_contents(envLoaderRuntime() . '/.env.local', "LOCAL=local\nKEY=local\n");
    file_put_contents(envLoaderRuntime() . '/.env.example', "IGNORED_SAMPLE=example\nAPP_ENV=sample\n");

    $environment = (new EnvLoader(envLoaderRuntime()))->load();

    expect($environment->string('APP_ENV'))->toBe('development')
        ->and($environment->string('BASE'))->toBe('base')
        ->and($environment->string('LOCAL'))->toBe('local')
        ->and($environment->string('KEY'))->toBe('local')
        ->and($environment->has('IGNORED_SAMPLE'))->toBeFalse();
});

it('keeps deployment environment precedence unless override is requested', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "APP_ENV=file\nFILE_ONLY=file\n");
    $_ENV['APP_ENV'] = 'process';

    $normal = (new EnvLoader(envLoaderRuntime()))->load();

    expect($normal->string('APP_ENV'))->toBe('process')
        ->and($normal->string('FILE_ONLY'))->toBe('file');

    $overridden = (new EnvLoader(envLoaderRuntime()))->load(override: true);
    expect($overridden->string('APP_ENV'))->toBe('file');
});

it('does not let unsupported server values mask valid dotenv values', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "INVALID_SERVER=file\n");
    $_SERVER['INVALID_SERVER'] = ['not', 'an', 'environment', 'scalar'];

    $environment = (new EnvLoader(envLoaderRuntime()))->load();

    expect($_ENV['INVALID_SERVER'])->toBe('file')
        ->and($environment->string('INVALID_SERVER'))->toBe('file');
});

it('loads dotenv values into env without mirroring them into server globals', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "FILE_ONLY=file\n");

    $environment = (new EnvLoader(envLoaderRuntime()))->load();

    expect($_ENV['FILE_ONLY'])->toBe('file')
        ->and($_SERVER)->not->toHaveKey('FILE_ONLY')
        ->and($environment->string('FILE_ONLY'))->toBe('file');
});

it('keeps read pure and supports an explicit filename list', function (): void {
    file_put_contents(envLoaderRuntime() . '/custom.env', "FILE_ONLY=custom\n");

    $loader = new EnvLoader(envLoaderRuntime(), filenames: ['custom.env']);

    expect($loader->read())->toBe(['FILE_ONLY' => 'custom'])
        ->and($_ENV)->not->toHaveKey('FILE_ONLY');
});

it('parses quoted values and escapes predictably', function (): void {
    file_put_contents(
        envLoaderRuntime() . '/.env',
        "MESSAGE=\"Hello World\"\nESCAPED=\"line1\\nline2\\ttab\"\n",
    );

    $environment = (new EnvLoader(envLoaderRuntime()))->load();

    expect($environment->string('MESSAGE'))->toBe('Hello World')
        ->and($environment->string('ESCAPED'))->toBe("line1\nline2\ttab");
});

it('validates variable syntax and required variables', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "INVALID-NAME=value\n");

    expect(fn() => (new EnvLoader(envLoaderRuntime()))->load())
        ->toThrow(EnvLoaderException::class, 'Invalid .env format');

    file_put_contents(envLoaderRuntime() . '/.env', "APP_NAME=Componenta\n");

    expect(fn() => (new EnvLoader(envLoaderRuntime(), required: ['APP_KEY']))->load())
        ->toThrow(EnvLoaderException::class, 'APP_KEY');
});

it('allows required values to come from deployment environment', function (): void {
    $_ENV['PROCESS_ONLY'] = 'present';

    $environment = (new EnvLoader(envLoaderRuntime(), required: ['PROCESS_ONLY']))->load();

    expect($environment->string('PROCESS_ONLY'))->toBe('present');
});

it('rejects unsafe filenames and invalid required names', function (): void {
    expect(fn() => new EnvLoader(envLoaderRuntime(), filenames: ['../.env']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => new EnvLoader(envLoaderRuntime(), required: ['BAD-NAME']))
        ->toThrow(InvalidArgumentException::class);
});
