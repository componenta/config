<?php

declare(strict_types=1);

use Componenta\Config\ConfigPath;

it('splits dot paths into stable segments', function (
    string $input,
    array $segments,
    bool $nested,
): void {
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
    'empty literal segment' => ['', [''], false],
]);
