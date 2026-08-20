<?php

declare(strict_types=1);

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\FileProvider;
use Componenta\Config\Reader\FileReaderInterface;

function fileProviderRuntime(): string
{
    static $root;
    return $root ??= sys_get_temp_dir() . '/componenta_file_provider_' . bin2hex(random_bytes(5));
}

beforeEach(function (): void { @mkdir(fileProviderRuntime(), 0700, true); });
afterEach(function (): void {
    foreach (glob(fileProviderRuntime() . '/*') ?: [] as $file) { is_file($file) && unlink($file); }
    @rmdir(fileProviderRuntime());
});

it('loads PHP and JSON files in sorted order and merges them', function (): void {
    file_put_contents(
        fileProviderRuntime() . '/01.php',
        "<?php return ['app' => ['name' => 'Componenta'], 'list' => ['a']];",
    );
    file_put_contents(fileProviderRuntime() . '/02.json', '{"app":{"debug":true},"list":["b"]}');

    $config = (new FileProvider(fileProviderRuntime() . '/*.{php,json}'))();

    expect($config)->toBe([
        'app' => ['name' => 'Componenta', 'debug' => true],
        'list' => ['a', 'b'],
    ]);
});

it('fails when a PHP config does not return an array', function (): void {
    file_put_contents(fileProviderRuntime() . '/invalid.php', "<?php return 'invalid';");

    expect(fn() => (new FileProvider(fileProviderRuntime() . '/*.php'))())
        ->toThrow(ConfigException::class, 'must return an array');
});

it('fails when JSON root is not configuration data', function (): void {
    file_put_contents(fileProviderRuntime() . '/invalid.json', '"string"');

    expect(fn() => (new FileProvider(fileProviderRuntime() . '/*.json'))())
        ->toThrow(ConfigException::class, 'object or array');
});

it('fails on malformed JSON', function (): void {
    file_put_contents(fileProviderRuntime() . '/invalid.json', '{');

    expect(fn() => (new FileProvider(fileProviderRuntime() . '/*.json'))())
        ->toThrow(ConfigException::class, 'Failed to parse JSON');
});

it('fails when a matched file has no supporting reader', function (): void {
    file_put_contents(fileProviderRuntime() . '/config.yaml', "app: test\n");

    expect(fn() => (new FileProvider(fileProviderRuntime() . '/*.yaml'))())
        ->toThrow(ConfigException::class, 'No configuration reader supports');
});

it('validates custom readers', function (): void {
    expect(fn() => new FileProvider('*.php', [new stdClass()]))
        ->toThrow(InvalidArgumentException::class, FileReaderInterface::class);
});

it('returns an empty array when nothing matches', function (): void {
    expect((new FileProvider(fileProviderRuntime() . '/*.php'))())->toBe([]);
});
