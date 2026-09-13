<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigOverride;
use Componenta\Config\Environment;

it('applies override markers when an incoming map replaces a different value shape', function (mixed $previous): void {
    $config = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['settings' => $previous],
        static fn(): array => ['settings' => [
            'kept' => ConfigOverride::replace(['enabled' => true]),
            'removed' => ConfigOverride::remove(),
        ]],
    )->config;

    expect($config->toArray())->toBe(['settings' => ['kept' => ['enabled' => true]]]);
})->with([
    'scalar to map' => 'previous',
    'list to map' => [['previous']],
]);

it('applies map overrides inside every appended list item', function (): void {
    $config = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['steps' => [[
            'name' => ConfigOverride::replace('first'),
            'removed' => ConfigOverride::remove(),
        ]]],
        static fn(): array => ['steps' => [[
            'name' => ConfigOverride::replace('second'),
            'nested' => [['enabled' => ConfigOverride::replace(true)]],
        ]]],
    )->config;

    expect($config->toArray())->toBe(['steps' => [
        ['name' => 'first'],
        ['name' => 'second', 'nested' => [['enabled' => true]]],
    ]]);
});

it('rejects override markers used as items of nested lists', function (): void {
    expect(fn() => (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['steps' => [[ConfigOverride::remove()]]],
    ))->toThrow(\Componenta\Config\Exception\ConfigMergeException::class, 'lists are append-only');
});
