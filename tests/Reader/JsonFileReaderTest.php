<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Reader;

use Componenta\Config\Exception\ConfigException;
use Componenta\Config\Reader\JsonFileReader;

it('returns null when the file extension is not JSON', function (): void {
    expect((new JsonFileReader())->readFile('config.php'))->toBeNull();
});

it('rejects a missing JSON file as unreadable', function (): void {
    $file = sys_get_temp_dir()
        . '/componenta_config_missing_'
        . bin2hex(random_bytes(6))
        . '.json';

    expect(fn() => (new JsonFileReader())->readFile($file))
        ->toThrow(ConfigException::class, 'is not readable');
});
