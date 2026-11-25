<?php

declare(strict_types=1);

namespace Componenta\Config\Tests\Contract;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigOverride;
use Componenta\Config\Environment;
use Componenta\Config\Exception\ConfigMergeException;
use stdClass;

it('replaces application arrays when a later contribution changes list and map shape', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            'list_to_map' => ['first', 'second'],
            'map_to_list' => ['enabled' => true],
        ],
        static fn(): array => [
            'list_to_map' => ['enabled' => false],
            'map_to_list' => ['replacement'],
        ],
    );

    expect($composition->config->toArray())->toBe([
        'list_to_map' => ['enabled' => false],
        'map_to_list' => ['replacement'],
    ]);
});

it('replaces the application root when provider contributions change list and map shape', function (): void {
    $listToMap = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['first', 'second'],
        static fn(): array => ['enabled' => false],
    )->config;
    $mapToList = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['enabled' => true],
        static fn(): array => ['replacement'],
    )->config;

    expect($listToMap->toArray())->toBe([
        'enabled' => false,
    ])->and($mapToList->toArray())->toBe([
        'replacement',
    ]);
});

it('appends lists and recursively merges maps by exact string and integer keys', function (): void {
    $config = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            'middleware' => ['auth'],
            'settings' => [
                'database' => ['host' => 'localhost'],
                'keys' => [
                    0 => 'integer-first',
                    '00' => 'string-first',
                    401 => 'unauthorized',
                ],
            ],
        ],
        static fn(): array => [
            'middleware' => ['csrf'],
            'settings' => [
                'database' => ['port' => 3306],
                'keys' => [
                    0 => 'integer-last',
                    '00' => 'string-last',
                    500 => 'server-error',
                ],
            ],
        ],
    )->config;

    expect($config->toArray())->toBe([
        'middleware' => ['auth', 'csrf'],
        'settings' => [
            'database' => ['host' => 'localhost', 'port' => 3306],
            'keys' => [
                0 => 'integer-last',
                '00' => 'string-last',
                401 => 'unauthorized',
                500 => 'server-error',
            ],
        ],
    ]);
});

it('replaces scalar object and incompatible application shapes with the later value', function (): void {
    $firstObject = new stdClass();
    $lastObject = new stdClass();

    $config = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            'scalar' => 'first',
            'map_to_scalar' => ['first' => true],
            'scalar_to_map' => 'first',
            'object' => $firstObject,
            'array_to_object' => ['first'],
            'object_to_array' => $firstObject,
        ],
        static fn(): array => [
            'scalar' => 'last',
            'map_to_scalar' => false,
            'scalar_to_map' => ['last' => true],
            'object' => $lastObject,
            'array_to_object' => $lastObject,
            'object_to_array' => ['last'],
        ],
    )->config;

    expect($config->toArray())->toBe([
        'scalar' => 'last',
        'map_to_scalar' => false,
        'scalar_to_map' => ['last' => true],
        'object' => $lastObject,
        'array_to_object' => $lastObject,
        'object_to_array' => ['last'],
    ]);
});

it('accepts the maximum merge depth and rejects the next level', function (): void {
    $deep = ['value' => 'leaf'];

    for ($depth = 0; $depth < 64; ++$depth) {
        $deep = ['nested' => $deep];
    }

    $config = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => $deep,
    )->config;
    $tooDeep = ['nested' => $deep];

    expect($config->toArray())->toBe($deep)
        ->and(fn() => (new ConfigFactory())->create(
            new Environment([]),
            static fn(): array => $tooDeep,
        ))->toThrow(
            ConfigMergeException::class,
            'maximum nesting depth of 64',
        );
});

it('rejects PHP references in provider contributions', function (): void {
    $value = 'unsafe';
    $contribution = ['nested' => []];
    $contribution['nested'][7] = &$value;

    expect(fn() => (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => $contribution,
    ))->toThrow(
        ConfigMergeException::class,
        "reference at \$config['nested'][7]",
    );
});

it('rejects PHP references hidden inside explicit replacements', function (int $layers): void {
    $value = 'unsafe';
    $replacement = ['value' => &$value];
    for ($i = 0; $i < $layers; ++$i) {
        $replacement = ConfigOverride::replace($replacement);
    }

    expect(fn() => (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => ['settings' => ['safe' => true]],
        static fn(): array => [
            'settings' => $replacement,
        ],
    ))->toThrow(
        ConfigMergeException::class,
        "reference at \$config['settings']['value']",
    );
})->with([1, 2, 3]);

it('supports explicit subtree replacement and key removal', function (): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            'feature' => [
                'enabled' => true,
                'settings' => ['first' => 1, 'second' => 2],
            ],
            'mode' => 'legacy',
            'obsolete' => 'value',
        ],
        static fn(): array => [
            'feature' => [
                'settings' => ConfigOverride::replace([]),
            ],
            'mode' => ConfigOverride::replace('strict'),
            'obsolete' => ConfigOverride::remove(),
        ],
    );

    expect($composition->config->toArray())->toBe([
        'feature' => [
            'enabled' => true,
            'settings' => [],
        ],
        'mode' => 'strict',
    ]);
});

it('rejects override markers inside append-only lists', function (): void {
    expect(fn() => (new ConfigFactory())->create(
        new Environment([]),
        static fn(): array => [
            'middleware' => [ConfigOverride::remove()],
        ],
    ))->toThrow(
        ConfigMergeException::class,
        'ConfigOverride can only be used as a map value',
    );
});
