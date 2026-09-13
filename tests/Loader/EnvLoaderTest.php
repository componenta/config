<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Loader;

use Componenta\Config\Environment;
use Componenta\Config\Exception\EnvLoaderException;
use Componenta\Config\Exception\InvalidConfigArgumentException;
use Componenta\Config\Loader\EnvLoader;

function envLoaderRuntime(): string
{
    return sys_get_temp_dir() . '/componenta_env_loader_' . getmypid();
}

/** @return list<string> */
function envLoaderKeys(): array
{
    return ['APP_ENV', 'APP_NAME', 'APP_KEY', 'DEBUG', 'BASE', 'LOCAL', 'KEY', 'MESSAGE', 'ESCAPED', 'FILE_ONLY', 'PROCESS_ONLY', 'IGNORED_SAMPLE', 'INVALID_SERVER'];
}

function clearEnvLoaderKeys(): void
{
    foreach (envLoaderKeys() as $key) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }
}

$restoreEnvironment = static function (): void {};

beforeEach(function () use (&$restoreEnvironment): void {
    $environment = $_ENV;
    $server = $_SERVER;
    $process = [];
    foreach (envLoaderKeys() as $key) {
        $process[$key] = getenv($key);
    }
    $restoreEnvironment = static function () use ($environment, $server, $process): void {
        $_ENV = $environment;
        $_SERVER = $server;
        foreach ($process as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    };
    clearEnvLoaderKeys();
    @mkdir(envLoaderRuntime(), 0700, true);
    foreach ([envLoaderRuntime() . '/*', envLoaderRuntime() . '/.*'] as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

afterEach(function () use (&$restoreEnvironment): void {
    $restoreEnvironment();
    foreach ([envLoaderRuntime() . '/*', envLoaderRuntime() . '/.*'] as $pattern) {
        foreach (glob($pattern) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
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
        ->and($normal->string('FILE_ONLY'))->toBe('file')
        ->and($_ENV['APP_ENV'])->toBe('process')
        ->and($_ENV)->not->toHaveKey('FILE_ONLY');

    $overridden = (new EnvLoader(envLoaderRuntime()))->load(override: true);
    expect($overridden->string('APP_ENV'))->toBe('file')
        ->and($_ENV['APP_ENV'])->toBe('process')
        ->and($_ENV)->not->toHaveKey('FILE_ONLY');
});

it('does not let unsupported server values mask valid dotenv values', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "INVALID_SERVER=file\n");
    $_SERVER['INVALID_SERVER'] = ['not', 'an', 'environment', 'scalar'];

    $environment = (new EnvLoader(envLoaderRuntime()))->load();

    expect($_ENV)->not->toHaveKey('INVALID_SERVER')
        ->and($environment->string('INVALID_SERVER'))->toBe('file');
});

it('returns dotenv values without mutating any process globals', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "FILE_ONLY=file\n");

    $environment = (new EnvLoader(envLoaderRuntime()))->load();

    expect($_ENV)->not->toHaveKey('FILE_ONLY')
        ->and($_SERVER)->not->toHaveKey('FILE_ONLY')
        ->and(getenv('FILE_ONLY'))->toBeFalse()
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
        ->toThrow(InvalidConfigArgumentException::class)
        ->and(fn() => new EnvLoader(envLoaderRuntime(), required: ['BAD-NAME']))
        ->toThrow(InvalidConfigArgumentException::class);
});

it('rejects an escaped final quote instead of treating it as a closing delimiter', function (int $slashes): void {
    file_put_contents(envLoaderRuntime() . '/.env', 'KEY="secret' . str_repeat(chr(92), $slashes) . '"');

    expect(fn() => (new EnvLoader(envLoaderRuntime()))->read())
        ->toThrow(EnvLoaderException::class, 'unbalanced');
})->with([1, 3]);

it('preserves quote and backslash semantics in complete dotenv values', function (string $source, string $expected): void {
    file_put_contents(envLoaderRuntime() . '/.env', 'KEY=' . $source);

    expect((new EnvLoader(envLoaderRuntime()))->read())->toBe(['KEY' => $expected]);
})->with([
    'empty double quoted' => ['""', ''],
    'one escaped backslash before closing quote' => ['"value' . str_repeat(chr(92), 2) . '"', 'value' . chr(92)],
    'two escaped backslashes before closing quote' => ['"value' . str_repeat(chr(92), 4) . '"', 'value' . str_repeat(chr(92), 2)],
    'escaped quote inside value' => ['"say ' . chr(92) . '"hello' . chr(92) . '""', 'say "hello"'],
    'literal single quoted escapes' => ["'" . 'line\n' . chr(92) . "'", 'line\n' . chr(92)],
    'double quoted control characters' => ['"line\r\nnext\ttab"', "line\r\nnext\ttab"],
]);

it('rejects invalid entries in the directory list', function (mixed $path): void {
    expect(fn() => new EnvLoader([$path]))
        ->toThrow(InvalidConfigArgumentException::class, 'paths');
})->with([
    'empty' => [''],
    'null byte' => ["bad\0path"],
    'non-string' => [42],
    'nested array' => [[]],
]);

it('rejects invalid entries in the filename list', function (mixed $filename): void {
    expect(fn() => new EnvLoader(envLoaderRuntime(), filenames: [$filename]))
        ->toThrow(InvalidConfigArgumentException::class, 'filenames');
})->with([
    'empty' => [''],
    'null byte' => ["bad\0.env"],
    'non-string' => [42],
    'nested array' => [[]],
]);

it('reports the physical line of malformed dotenv assignments', function (string $line, string $reason): void {
    $file = envLoaderRuntime() . DIRECTORY_SEPARATOR . '.env';
    file_put_contents($file, "# comment\r\n\r\n" . $line);

    expect(fn() => (new EnvLoader(envLoaderRuntime()))->read())
        ->toThrow(EnvLoaderException::class, "line 3 in file '{$file}': {$reason}");
})->with([
    'no assignment' => ['KEY', 'expected KEY=VALUE assignment'],
    'invalid name' => ['BAD-NAME=value', 'invalid variable name'],
    'missing double quote' => ['KEY="value', 'unbalanced'],
    'missing single quote' => ["KEY='value", 'unbalanced'],
    'only opening quote' => ['KEY="', 'unbalanced'],
]);

it('continues past comments and blank lines while preserving empty values and equals signs', function (): void {
    file_put_contents(envLoaderRuntime() . '/.env', "# comment\r\n\r\n EMPTY = \r\n KEY = a=b#c \r\n");

    expect((new EnvLoader(envLoaderRuntime()))->read())->toBe(['EMPTY' => '', 'KEY' => 'a=b#c']);
});

it('distinguishes an empty dotenv file from the absence of selected files', function (): void {
    $loader = new EnvLoader(envLoaderRuntime());
    expect($loader->read())->toBeNull();

    file_put_contents(envLoaderRuntime() . '/.env', '');
    expect($loader->read())->toBe([])
        ->and((new EnvLoader(envLoaderRuntime(), filenames: []))->read())->toBeNull();
});
