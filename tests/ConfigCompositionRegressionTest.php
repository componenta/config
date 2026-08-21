<?php

declare(strict_types=1);

use Componenta\Config\ConfigKey;
use Componenta\Config\ConfigLoader;
use Componenta\Config\Environment;

use function Componenta\Config\config_merge;

final class ConfigCompositionCallablePairFixture
{
    public static function decorate(string $value): string
    {
        return $value;
    }
}

it('preserves parameter resolver priorities across providers', function (): void {
    $config = ConfigLoader::load(
        new Environment([]),
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::PARAMETER_RESOLVERS => [1200 => 'ResolverA'],
            ],
        ],
        static fn(): array => [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::PARAMETER_RESOLVERS => [900 => 'ResolverB'],
            ],
        ],
    );

    expect($config->get(ConfigKey::DEPENDENCIES)[ConfigKey::PARAMETER_RESOLVERS])
        ->toBe([1200 => 'ResolverA', 900 => 'ResolverB']);
});

it('replaces one resolver atomically when the same priority is reused', function (): void {
    $merged = config_merge(
        [ConfigKey::DEPENDENCIES => [ConfigKey::PARAMETER_RESOLVERS => [
            900 => ['resolver.a', 'create'],
            700 => 'ResolverC',
        ]]],
        [ConfigKey::DEPENDENCIES => [ConfigKey::PARAMETER_RESOLVERS => [
            900 => ['resolver.b', 'build'],
        ]]],
    );

    expect($merged[ConfigKey::DEPENDENCIES][ConfigKey::PARAMETER_RESOLVERS])
        ->toBe([
            900 => ['resolver.b', 'build'],
            700 => 'ResolverC',
        ]);
});

it('treats factory alias and service values as atomic entries', function (): void {
    $merged = config_merge(
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => ['service' => ['factory.a', 'create']],
            ConfigKey::ALIASES => ['alias' => 'TargetA'],
            ConfigKey::SERVICES => ['settings' => ['old' => true]],
        ]],
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::FACTORIES => ['service' => ['factory.b', 'build']],
            ConfigKey::ALIASES => ['alias' => 'TargetB'],
            ConfigKey::SERVICES => ['settings' => ['new' => true]],
        ]],
    );

    $dependencies = $merged[ConfigKey::DEPENDENCIES];

    expect($dependencies[ConfigKey::FACTORIES]['service'])->toBe(['factory.b', 'build'])
        ->and($dependencies[ConfigKey::ALIASES]['alias'])->toBe('TargetB')
        ->and($dependencies[ConfigKey::SERVICES]['settings'])->toBe(['new' => true]);
});

it('appends list-like dependency sections and nested delegator callable pairs', function (): void {
    $merged = config_merge(
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::INVOKABLES => ['ServiceA'],
            ConfigKey::DELEGATORS => [
                'service' => [[ConfigCompositionCallablePairFixture::class, 'decorate']],
            ],
            ConfigKey::ATTRIBUTE_DEFINITIONS => ['DefinitionA'],
            ConfigKey::ATTRIBUTE_CAPABILITIES => ['CapabilityA'],
        ]],
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::INVOKABLES => ['ServiceB'],
            ConfigKey::DELEGATORS => [
                'service' => [['DecoratorB', 'decorate']],
            ],
            ConfigKey::ATTRIBUTE_DEFINITIONS => ['DefinitionB'],
            ConfigKey::ATTRIBUTE_CAPABILITIES => ['CapabilityB'],
        ]],
    );

    $dependencies = $merged[ConfigKey::DEPENDENCIES];

    expect($dependencies[ConfigKey::INVOKABLES])->toBe(['ServiceA', 'ServiceB'])
        ->and($dependencies[ConfigKey::DELEGATORS]['service'])->toBe([
            [ConfigCompositionCallablePairFixture::class, 'decorate'],
            ['DecoratorB', 'decorate'],
        ])->and($dependencies[ConfigKey::ATTRIBUTE_DEFINITIONS])->toBe(['DefinitionA', 'DefinitionB'])
        ->and($dependencies[ConfigKey::ATTRIBUTE_CAPABILITIES])->toBe(['CapabilityA', 'CapabilityB']);
});

it('rejects a direct callable pair because its meaning would change after composition', function (): void {
    expect(fn() => config_merge([], [
        ConfigKey::DEPENDENCIES => [
            ConfigKey::DELEGATORS => [
                'service' => [ConfigCompositionCallablePairFixture::class, 'decorate'],
            ],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'callable pairs must be nested');
});

it('rejects malformed delegator sections and pipelines before merge can mutate their shape', function (): void {
    expect(fn() => config_merge([], [
        ConfigKey::DEPENDENCIES => [ConfigKey::DELEGATORS => 'invalid'],
    ]))->toThrow(InvalidArgumentException::class, 'must be an array')
        ->and(fn() => config_merge([], [
            ConfigKey::DEPENDENCIES => [
                ConfigKey::DELEGATORS => ['service' => ['first' => 'DecoratorA']],
            ],
        ]))->toThrow(InvalidArgumentException::class, 'pipeline list');
});

it('rejects unsupported and structurally invalid DI v5 sections before merge', function (): void {
    expect(fn() => config_merge([], [
        ConfigKey::DEPENDENCIES => ['extension' => []],
    ]))->toThrow(InvalidArgumentException::class, 'Unsupported DI v5 dependency section')
        ->and(fn() => config_merge([], [
            ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => 'invalid'],
        ]))->toThrow(InvalidArgumentException::class, 'must be an array')
        ->and(fn() => config_merge([], [
            ConfigKey::DEPENDENCIES => [ConfigKey::PARAMETER_RESOLVERS_REPLACE => 1],
        ]))->toThrow(InvalidArgumentException::class, 'must be bool')
        ->and(fn() => config_merge([], [
            ConfigKey::DEPENDENCIES => [ConfigKey::ATTRIBUTE_DEFINITIONS => ['named' => 'Definition']],
        ]))->toThrow(InvalidArgumentException::class, 'must be a list')
        ->and(fn() => config_merge([], [
            ConfigKey::DEPENDENCIES => [0 => []],
        ]))->toThrow(InvalidArgumentException::class, 'Unsupported DI v5 dependency section');
});

it('rejects a non-array dependency root before it can replace valid dependencies', function (): void {
    expect(fn() => config_merge(
        [ConfigKey::DEPENDENCIES => [ConfigKey::SERVICES => ['service' => 'ready']]],
        [ConfigKey::DEPENDENCIES => 'invalid'],
    ))->toThrow(InvalidArgumentException::class, 'Dependency root');
});

it('preserves keyed invokables while appending numeric invokables', function (): void {
    $merged = config_merge(
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::INVOKABLES => ['service' => 'ServiceA', 'First'],
        ]],
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::INVOKABLES => ['service' => 'ServiceB', 'Second'],
        ]],
    );

    expect($merged[ConfigKey::DEPENDENCIES][ConfigKey::INVOKABLES])->toBe([
        'service' => 'ServiceB',
        0 => 'First',
        1 => 'Second',
    ]);
});

it('appends real application lists and preserves integer keys in application maps', function (): void {
    expect(config_merge(
        [
            'middleware' => ['auth'],
            'errors' => [404 => 'not-found', 401 => 'unauthorized'],
        ],
        [
            'middleware' => ['csrf'],
            'errors' => [500 => 'server-error', 404 => 'custom-not-found'],
        ],
    ))->toBe([
        'middleware' => ['auth', 'csrf'],
        'errors' => [
            404 => 'custom-not-found',
            401 => 'unauthorized',
            500 => 'server-error',
        ],
    ]);
});

it('treats mixed-key application arrays as maps rather than reindexing numeric keys', function (): void {
    expect(config_merge(
        ['mixed' => [0 => 'base', 'name' => 'first']],
        ['mixed' => [0 => 'override', 'name' => 'second']],
    ))->toBe([
        'mixed' => [0 => 'override', 'name' => 'second'],
    ]);
});

it('uses recursive map merge semantics outside the dependency root', function (): void {
    expect(config_merge(
        ['feature' => ['settings' => ['a' => 1]]],
        ['feature' => ['settings' => ['b' => 2]]],
    ))->toBe([
        'feature' => ['settings' => ['a' => 1, 'b' => 2]],
    ]);
});

it('lets later scalar replacement flags win', function (): void {
    $merged = config_merge(
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
            ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => true,
        ]],
        [ConfigKey::DEPENDENCIES => [
            ConfigKey::PARAMETER_RESOLVERS_REPLACE => false,
            ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE => false,
        ]],
    );

    expect($merged[ConfigKey::DEPENDENCIES][ConfigKey::PARAMETER_RESOLVERS_REPLACE])
        ->toBeFalse()
        ->and($merged[ConfigKey::DEPENDENCIES][ConfigKey::ATTRIBUTE_DEFINITIONS_REPLACE])
        ->toBeFalse();
});
