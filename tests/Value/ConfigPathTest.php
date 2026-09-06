<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Value;

use Componenta\Config\ConfigPath;
use Componenta\Config\Exception\InvalidConfigArgumentException;

it('splits valid dot paths into stable segments', function (
    string $input,
    array $segments,
    bool $nested,
): void {
    if ($segments === []) {
        throw new \LogicException('A valid ConfigPath dataset must contain at least one segment.');
    }

    $path = new ConfigPath($input);

    expect($path->toArray())->toBe($segments)
        ->and($path->first())->toBe($segments[0])
        ->and($path->last())->toBe($segments[array_key_last($segments)])
        ->and($path->isNested())->toBe($nested)
        ->and((string) $path)->toBe($input);
})->with([
    'single segment' => ['database', ['database'], false],
    'two segments' => ['database.host', ['database', 'host'], true],
    'deep path' => ['database.connections.primary', ['database', 'connections', 'primary'], true],
]);

it('rejects empty paths and empty path segments', function (string $path): void {
    expect(fn() => new ConfigPath($path))->toThrow(InvalidConfigArgumentException::class);
})->with([
    'empty path' => '',
    'leading empty segment' => '.database',
    'trailing empty segment' => 'database.',
    'middle empty segment' => 'database..host',
]);
