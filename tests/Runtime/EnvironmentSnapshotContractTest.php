<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Runtime;

use Componenta\Config\ConfigPath;
use Componenta\Config\Environment;
use Componenta\Config\Exception\InvalidConfigValueException;

it('takes independent snapshots with process then scalar server then environment precedence', function (): void {
    $key = 'COMPONENTA_CONFIG_SNAPSHOT_' . bin2hex(random_bytes(6));
    $integerKey = $key . '_INT';
    $floatKey = $key . '_FLOAT';
    $booleanKey = $key . '_BOOL';
    $environment = $_ENV;
    $server = $_SERVER;
    putenv($key . '=native');

    try {
        $_ENV = ['' => 'invalid', 42 => 'invalid'];
        $_SERVER = ['' => 'invalid', 42 => 'invalid', $key => ['not scalar']];
        $native = Environment::fromGlobals();
        expect($native->get($key))->toBe('native')
            ->and($native->has(''))->toBeFalse()
            ->and(array_key_exists(42, $native->toArray()))->toBeFalse();

        $_SERVER = [$key => 'server', $integerKey => 7, $floatKey => 0.5, $booleanKey => false];
        expect(Environment::fromGlobals([$key, $integerKey, $floatKey, $booleanKey])->toArray())
            ->toBe([$key => 'server', $integerKey => 7, $floatKey => 0.5, $booleanKey => false]);

        $_ENV[$key] = null;
        $nullable = Environment::fromGlobals([$key]);
        $_ENV[$key] = 'changed';
        putenv($key . '=changed');
        expect($nullable->has($key))->toBeTrue()
            ->and($nullable->get($key))->toBeNull()
            ->and($native->get($key))->toBe('native')
            ->and(Environment::fromGlobals([])->toArray())->toBe([]);
    } finally {
        $_ENV = $environment;
        $_SERVER = $server;
        putenv($key);
    }
});

it('finds all matching prefix values even when unrelated keys occur between them', function (): void {
    $environment = new Environment(['APP_NAME' => 'app', 'DB_HOST' => 'localhost', 'DEBUG' => false, 'DB_PORT' => '5432']);

    expect($environment->prefix('DB_', removePrefix: true))->toBe(['HOST' => 'localhost', 'PORT' => '5432']);
});

it('reports the normalized environment name when typed conversion fails', function (string $accessor, mixed $invalid): void {
    $environment = new Environment(['DATABASE_VALUE' => $invalid]);

    expect(fn() => $environment->{$accessor}(new ConfigPath('database.value')))
        ->toThrow(InvalidConfigValueException::class, 'DATABASE_VALUE');
})->with([
    'string' => ['string', []],
    'integer' => ['int', '1.5'],
    'float' => ['float', '1e9999'],
    'boolean' => ['bool', 'ambiguous'],
]);

it('ignores numeric process variable names when taking a runtime snapshot', function (): void {
    $numericName = (string) random_int(100000000, 999999999);
    $validName = 'COMPONENTA_NUMERIC_NEIGHBOR_' . bin2hex(random_bytes(6));
    $numericPrevious = getenv($numericName);
    $validPrevious = getenv($validName);

    try {
        putenv($numericName . '=unsupported');
        putenv($validName . '=retained');

        $snapshot = Environment::fromGlobals();
        expect($snapshot->has($numericName))->toBeFalse()
            ->and($snapshot->get($validName))->toBe('retained');
    } finally {
        putenv($numericPrevious === false ? $numericName : $numericName . '=' . $numericPrevious);
        putenv($validPrevious === false ? $validName : $validName . '=' . $validPrevious);
    }
});
